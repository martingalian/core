<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\NotRunnable;
use Martingalian\Core\States\Pending;

final class StepObserver
{
    public function creating(Step $step): void
    {
        if (empty($step->block_uuid)) {
            $step->block_uuid = Str::uuid()->toString();
        }

        if (empty($step->state) && $step->type === 'default') {
            $step->state = new Pending($step);
        }

        if ($step->type === 'resolve-exception') {
            $step->state = new NotRunnable($step);
        }

        if ($step->index === 0) {
            $step->index = 1;
        }
    }

    public function created(Step $step): void
    {
        // info_if("[StepObserver.created] Step created with id={$step->id}, block_uuid={$step->block_uuid}, state={$step->state}, index={$step->index}, class={$step->class}, queue={$step->queue}, arguments=".json_encode($step->arguments).", child_block_uuid={$step->child_block_uuid}, created_at={$step->created_at}, updated_at={$step->updated_at}");
    }
}
