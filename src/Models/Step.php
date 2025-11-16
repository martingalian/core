<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Abstracts\StepStatus;
use Martingalian\Core\Concerns\Step\HasActions;
use Martingalian\Core\States\Cancelled;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string $block_uuid
 * @property string $type
 * @property StepStatus $state
 * @property string|null $class
 * @property int|null $index
 * @property array|null $response
 * @property string|null $error_message
 * @property string|null $error_stack_trace
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string|null $child_block_uuid
 * @property string $execution_mode
 * @property int $double_check
 * @property string $queue
 * @property array|null $arguments
 * @property int $retries
 * @property \Illuminate\Support\Carbon|null $dispatch_after
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int $duration
 * @property string|null $hostname
 * @property bool $was_notified
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read string|null $group
 */
final class Step extends BaseModel
{
    use HasActions, HasFactory, HasStates;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'response' => 'array',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dispatch_after' => 'datetime',

        'was_throttled' => 'boolean',
        'is_throttled' => 'boolean',

        'state' => StepStatus::class,
    ];

    public static function concludedStepStates()
    {
        return [Completed::class, Skipped::class];
    }

    public static function failedStepStates()
    {
        return [Failed::class, Stopped::class];
    }

    public static function terminalStepStates(): array
    {
        return [
            Completed::class,
            Skipped::class,
            Cancelled::class,
            Failed::class,
            Stopped::class,
        ];
    }

    /**
     * Get a random dispatch group from available groups.
     * Delegates to StepsDispatcher::getDispatchGroup().
     */
    public static function getDispatchGroup(): ?string
    {
        return StepsDispatcher::getDispatchGroup();
    }

    public function stepTick()
    {
        return $this->belongsTo(StepsDispatcherTicks::class, 'tick_id');
    }

    public function scopeDispatchable(Builder $query)
    {
        return $query->where('state', Pending::class)
            ->where('type', 'default');
    }

    public function relatable()
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query)
    {
        return $query->where('steps.state', Pending::class);
    }

    public function hasChildren(): bool
    {
        if (! $this->isParent()) {
            return false;
        }

        return self::where('block_uuid', $this->child_block_uuid)->exists();
    }

    public function parentStep()
    {
        return self::where('child_block_uuid', $this->block_uuid)->first();
    }

    public function isChild(): bool
    {
        return self::where('child_block_uuid', $this->block_uuid)->exists();
    }

    public function isParent(): bool
    {
        return ! empty($this->child_block_uuid);
    }

    public function parentIsRunning(): bool
    {
        $parent = $this->parentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    public function isOrphan(): bool
    {
        return is_null($this->child_block_uuid) && is_null($this->parentStep());
    }

    public function previousIndexIsConcluded()
    {
        // Log the current step's index to check the initial state
        info_if("[previousIndexIsConcluded] Evaluating previous index for Step ID {$this->id} with index {$this->index} in block {$this->block_uuid}");

        // If the current step is the first step (index 1), it is always concluded
        if ($this->index === 1) {
            info_if("[previousIndexIsConcluded] Step ID {$this->id} is the first step (index 1), returning true.");

            /*
            $this->logApplicationEvent(
                "Step ID {$this->id} is the first step (index 1), returning true.",
                self::class,
                __FUNCTION__
            );
            */

            return true;
        }

        // Added: TODO: Make a pest test.
        // I don't have an index, I am a child and my parent is already running.
        if ($this->index === null && $this->isChild() && $this->parentIsRunning()) {
            info_if("[previousIndexIsConcluded] Step ID {$this->id} is a child, its parent is already running, and I dont have an index. Returning true");

            /*
            $this->logApplicationEvent(
                "Step ID {$this->id} is a child, its parent is already running, and I dont have an index. Returning true",
                self::class,
                __FUNCTION__
            );
            */

            return true;
        }

        // Check if there are any pending resolve-exception steps
        $hasPendingResolveException = self::where('block_uuid', $this->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        // Build the query dynamically based on the condition
        $query = self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1);

        // If there are pending resolve-exception steps, change the type condition
        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        // Execute the query
        $previousSteps = $query->get();

        // Log the number of previous steps found
        info_if('[previousIndexIsConcluded] Found '.$previousSteps->count()." previous step(s) for Step ID {$this->id}.");

        // If no previous steps are found, log and return false
        if ($previousSteps->isEmpty()) {
            info_if("[previousIndexIsConcluded] No previous steps found for Step ID {$this->id}, returning false.");

            /*
            $this->logApplicationEvent(
                "No previous steps found for Step ID {$this->id}, returning false.",
                self::class,
                __FUNCTION__
            );
            */

            return false;
        }

        $previousStepsIds = $previousSteps->pluck('id')->implode(',');
        info_if('Previous Steps Ids: '.$previousStepsIds);

        // Log each previous step's state
        $previousSteps->each(function ($step) {
            $step->refresh();
            info_if("[previousIndexIsConcluded] Previous Step ID {$step->id} has state ".get_class($step->state));
        });

        // Check if all previous steps are concluded, i.e., have a concluded state
        $result = $previousSteps->every(
            fn ($step) => in_array(get_class($step->state), $this->concludedStepStates(), true)
        );

        // Log the result of the concluded check
        if ($result) {
            info_if("[previousIndexIsConcluded] All previous steps for Step ID {$this->id} have concluded.");

            /*
            $this->logApplicationEvent(
                "All previous steps for Step ID {$this->id} have concluded.",
                self::class,
                __FUNCTION__
            );
            */
        } else {
            info_if("[previousIndexIsConcluded] Not all previous steps for Step ID {$this->id} have concluded.");

            /*
            $this->logApplicationEvent(
                "Not all previous steps for Step ID {$this->id} have concluded.",
                self::class,
                __FUNCTION__
            );
            */
        }

        // Return the result of the check
        return $result;
    }

    public function childSteps()
    {
        return $this->hasMany(self::class, 'block_uuid', 'child_block_uuid');
    }

    public function childStepsAreConcludedFromMap($childStepsByBlock): bool
    {
        info_if("➡️ [Step.childStepsAreConcludedFromMap] START check for parent ID {$this->id} / child_block_uuid: {$this->child_block_uuid}");

        // Accept either array-accessible or Collection maps.
        $children = $childStepsByBlock[$this->child_block_uuid]
        ?? (method_exists($childStepsByBlock, 'get') ? $childStepsByBlock->get($this->child_block_uuid) : null);

        if (empty($children)) {
            info_if("⛔ No children found for block {$this->child_block_uuid}, returning FALSE.");

            return false;
        }

        // If it's not a Collection, wrap once to standardize iteration.
        if (! $children instanceof \Illuminate\Support\Collection) {
            $children = collect($children);
        }

        info_if('🔍 Found '.$children->count()." children for block {$this->child_block_uuid}");

        foreach ($children as $child) {
            $stateClass = get_class($child->state);
            info_if("🧒 Child ID {$child->id} | State: ".class_basename($stateClass));

            if (! in_array($stateClass, $this->concludedStepStates(), true)) {
                info_if("❌ Child ID {$child->id} is NOT in concluded states. Returning FALSE.");

                return false;
            }

            if ($child->isParent()) {
                info_if("🔁 Child ID {$child->id} is a parent. Recursing into its children.");
                $recurse = $child->childStepsAreConcludedFromMap($childStepsByBlock);
                info_if("🔁 Recursion result for child ID {$child->id}: ".($recurse ? '✅ TRUE' : '❌ FALSE'));
                if (! $recurse) {
                    info_if("⛔ Recursion failed for child ID {$child->id}. Returning FALSE.");

                    return false;
                }
            }
        }

        info_if("✅ All children (and grandchildren) of parent ID {$this->id} are concluded. Returning TRUE.");

        return true;
    }

    public function childStepsAreConcluded(): bool
    {
        $children = $this->childSteps()->get();

        if ($children->isEmpty()) {
            return false;
        }

        foreach ($children as $child) {
            if (! in_array(get_class($child->state), $this->concludedStepStates(), true)) {
                return false;
            }

            if ($child->isParent() && ! $child->childStepsAreConcluded()) {
                return false;
            }
        }

        return true;
    }

    public function getPrevious()
    {
        return self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1)
            ->get();
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\StepFactory::new();
    }
}
