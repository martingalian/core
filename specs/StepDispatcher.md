# Step Dispatcher System

## Overview
Step-based job execution system using state machines for reliable, sequential task processing with parent-child dependencies, automatic retry logic, and failure cascading.

## Core Classes

### Step Model
**Location**: `packages/martingalian/core/src/Models/Step.php`

**Traits**:
- `HasStates` (Spatie state machine)
- `HasActions` (custom step actions)

**Key Fields**:
- `id`, `uuid`
- `parent_step_id` (nullable, for child steps)
- `relatable_type`, `relatable_id` (polymorphic - what this step operates on)
- `action` (job class name to dispatch)
- `arguments` (JSON - job constructor args)
- `queue` (queue name: 'default', 'priority', 'candles', 'indicators', or hostname)
- `group` (dispatch group for load balancing)
- `state` (StepStatus enum)
- `dispatch_after` (datetime - delay dispatching until)
- `started_at`, `completed_at`
- `error_message`, `error_stack_trace`
- `response` (JSON - job result data)

**Static State Lists**:
```php
Step::concludedStepStates()  // [Completed, Skipped]
Step::failedStepStates()     // [Failed, Stopped]
Step::terminalStepStates()   // [Completed, Skipped, Cancelled, Failed, Stopped]
```

**Scopes**:
- `scopeDispatchable()` - Pending + type='default'

### StepsDispatcher Model
**Location**: `packages/martingalian/core/src/Models/StepsDispatcher.php`

**Purpose**: Manages per-group dispatcher locks and round-robin group selection for workflow-level parallelism

**Named Groups** (10 total):
- `alpha`, `beta`, `gamma`, `delta`, `epsilon`
- `zeta`, `eta`, `theta`, `iota`, `kappa`

**Key Methods**:
- `startDispatch(?string $group)` - Acquire per-group lock, create tick, return true if successful
- `endDispatch(int $progress, ?string $group)` - Complete tick, release lock
- `getNextGroup(): string` - Round-robin group selection with microsecond precision

**Round-Robin Selection** (`getNextGroup()`):
```php
public static function getNextGroup(): string
{
    return DB::transaction(function () {
        // Select group with oldest last_selected_at (NULL = never selected = highest priority)
        // lockForUpdate() ensures only one process at a time can select this row
        $dispatcher = self::query()
            ->whereNotNull('group')
            ->orderByRaw('last_selected_at IS NULL DESC') // NULLs first
            ->orderBy('last_selected_at', 'asc')
            ->lockForUpdate()
            ->first();

        if (! $dispatcher) {
            return 'alpha'; // Fallback
        }

        // Update with microsecond precision for unique timestamps
        DB::table('steps_dispatcher')
            ->where('id', $dispatcher->id)
            ->update(['last_selected_at' => DB::raw('NOW(6)')]);

        return $dispatcher->group;
    });
}
```

**Why `NOW(6)` instead of `now()`**:
- Laravel's `now()` doesn't preserve microseconds when saving to MySQL
- All timestamps become identical (`.000000`), breaking round-robin ordering
- MySQL's `NOW(6)` returns current time with microsecond precision
- Ensures unique `last_selected_at` values for proper group rotation

**Fields**:
- `group` (named group: alpha through kappa)
- `can_dispatch` (per-group lock flag)
- `current_tick_id` (foreign key to steps_dispatcher_ticks)
- `last_tick_completed` (datetime)
- `last_selected_at` (datetime with microseconds - for round-robin ordering)

### StepsDispatcherTicks Model
**Location**: `packages/martingalian/core/src/Models/StepsDispatcherTicks.php`

**Purpose**: Audit log of each dispatcher tick execution

**Fields**:
- `dispatch_group`
- `steps_dispatched` (count)
- `duration_ms`
- `created_at`

### StepDispatcher (Support Class)
**Location**: `packages/martingalian/core/src/Support/StepDispatcher.php`

**Purpose**: Core dispatcher logic - runs in scheduled command every second

**Main Method**: `dispatch(?string $group)`

**Execution Flow** (runs sequentially until early return):
1. **Acquire lock** - `StepsDispatcher::startDispatch($group)` (pessimistic)
2. **Circuit breaker check** - Verify `can_dispatch_steps` is enabled (skip tick if disabled)
3. **Step 0**: `skipAllChildStepsOnParentAndChildSingleStep()` - Mark children as Skipped if parent is Skipped
4. **Step 1**: `cascadeCancelledSteps()` - Cascade Cancelled state to children
5. **Step 2**: `promoteResolveExceptionSteps()` - Handle JustResolveException recovery
6. **Step 3**: `transitionParentsToFailed()` - Mark parents Failed if all children failed
7. **Step 4**: `cascadeFailureToChildren()` - Mark children Failed if parent is Failed
8. **Step 5**: `transitionParentStepsToCompleted()` - Mark parents Completed if all children concluded
9. **Step 6**: `retryExhaustedSteps()` - Transition to Failed if max retries reached
10. **Step 7**: `pickDispatchableSteps()` - Find Pending steps ready to dispatch
11. **Step 8**: `dispatchSteps()` - Dispatch jobs to queue

Each step can return early if it performs work, preventing cascading effects in same tick.

## State Machine

### States
**Location**: `packages/martingalian/core/src/States/`

| State | Description | Terminal? |
|-------|-------------|-----------|
| `Pending` | Created, waiting to be dispatched | No |
| `Dispatched` | Job dispatched to queue (transient) | No |
| `Running` | Job is executing | No |
| `Completed` | Successfully finished | Yes |
| `Failed` | Error occurred, may retry | Yes |
| `Skipped` | Parent was skipped | Yes |
| `Cancelled` | Manually cancelled or parent cancelled | Yes |
| `Stopped` | Stopped by system (no retry) | Yes |
| `NotRunnable` | Cannot be run (missing dependencies) | Yes |

**Terminal States**: Once reached, step will never execute again

### State Transitions

**Valid Transitions**:
```
Pending → Dispatched → Running → Completed
                               → Failed (→ Pending if retry)
                               → Stopped
        → Cancelled
        → Skipped
        → NotRunnable

Running → Pending (job calls retryJob())
```

**Transition Classes**:
- `PendingToDispatched` - When job is dispatched to queue

**State Properties**:
- Each state is a class extending `StepStatus`
- Uses Spatie's `HasStates` trait for state machine logic
- Transitions can have guards (prevent invalid transitions)

## Business Rules

### Parent-Child Dependencies

**Rule 1: Parent Completion**
- Parent cannot complete until ALL children are in concluded states (Completed, Skipped)
- Enforced in: `transitionParentStepsToCompleted()`

**Rule 2: Parent Failure Cascades**
- If parent transitions to Failed, ALL children must transition to Failed
- Enforced in: `cascadeFailureToChildren()`

**Rule 3: Parent Skip Cascades**
- If parent is Skipped, ALL children must transition to Skipped
- Enforced in: `skipAllChildStepsOnParentAndChildSingleStep()`

**Rule 4: Parent Cancellation Cascades**
- If parent is Cancelled, ALL children must transition to Cancelled
- Enforced in: `cascadeCancelledSteps()`

**Rule 5: Child Failure Propagates Up**
- If ALL children are in failed states (Failed, Stopped), parent transitions to Failed
- Enforced in: `transitionParentsToFailed()`

### Dispatch Rules

**Rule 6: Dispatch Timing**
- Step only dispatchable if `dispatch_after <= now()`
- Allows delayed execution

**Rule 7: Dispatch Groups**
- Each server handles specific dispatch groups
- Steps filtered by group to prevent race conditions

**Rule 8: Pessimistic Locking**
- Dispatcher uses DB lock via `StepsDispatcher::startDispatch()`
- Only one dispatcher tick per group at a time

**Rule 9: Sequential Execution**
- Dispatcher steps run sequentially
- Early return if any step performs work
- Prevents cascading effects in same tick

### Retry Rules

**Rule 10: Retry via State Transition**
- Jobs call `$this->retryJob()` to transition Running → Pending
- Sets `dispatch_after` for backoff delay

**Rule 11: Max Retries**
- After max retries, step transitions to Failed
- Enforced in: `retryExhaustedSteps()`

**Rule 12: Retry Backoff**
- Each retry can set custom `dispatch_after`
- Allows exponential backoff

## Group Assignment (StepObserver)

**Location**: `packages/martingalian/core/src/Observers/StepObserver.php`

**Purpose**: Automatically assign groups to steps for workflow-level parallelism. All steps in a workflow share the same group for coherent processing.

### Assignment Logic

**In `creating()` event** (and mirrored in `saving()` for updates):

```php
// Group assignment for workflow-level parallelism:
// 1) If parent exists (where parent.child_block_uuid = my block_uuid) → inherit parent's group
// 2) No parent → I'm a root step → select group via round-robin from steps_dispatcher
if (empty($step->group)) {
    // CRITICAL: Only look for parent if block_uuid is set
    // Otherwise NULL = NULL matches everything (SQL: WHERE column IS NULL)
    $parentStep = null;
    if (! empty($step->block_uuid)) {
        $parentStep = Step::query()
            ->where('child_block_uuid', $step->block_uuid)
            ->whereNotNull('group')
            ->first();
    }

    if ($parentStep) {
        // Child step → inherit parent's group
        $step->group = $parentStep->group;
    } else {
        // Root step → select group via round-robin
        $step->group = StepsDispatcher::getNextGroup();
    }
}
```

### Parent-Child Linking via `block_uuid`

**How it works**:
- Parent step sets `child_block_uuid` pointing to child block
- Child steps have their own `block_uuid`
- Query: `WHERE child_block_uuid = $step->block_uuid` finds the parent

**Example workflow**:
```
Parent Step (block_uuid=A, child_block_uuid=B, group=alpha)
    └── Child Step 1 (block_uuid=B, group=alpha) ← inherits from parent
    └── Child Step 2 (block_uuid=B, group=alpha) ← inherits from parent
        └── Grandchild Step (block_uuid=C, group=alpha) ← inherits from Child Step 2
```

### Critical Bug Fix: NULL block_uuid

**The Problem**:
When `block_uuid` is NULL (or empty), the query:
```php
Step::query()->where('child_block_uuid', $step->block_uuid)->first();
```
Becomes:
```sql
WHERE child_block_uuid IS NULL
```
This matches ANY step without a child block, causing ALL subsequent steps to inherit the first step's group.

**The Fix**:
```php
// Skip parent lookup if block_uuid is empty
if (! empty($step->block_uuid)) {
    $parentStep = Step::query()
        ->where('child_block_uuid', $step->block_uuid)
        ->first();
}
```

### Distribution Results

With proper group assignment, steps distribute evenly:
```
Group     | Count
----------|-------
alpha     | 464
beta      | 464
gamma     | 464
delta     | 464
epsilon   | 464
zeta      | 464
eta       | 464
theta     | 464
iota      | 464
kappa     | 464
----------|-------
Total     | 4640
```

### Important: Transaction Isolation

**⚠️ Do NOT wrap `Step::create()` calls in `DB::transaction()`**

When step creation is wrapped in an outer transaction, Laravel uses savepoints instead of real transactions. The `lockForUpdate()` in `getNextGroup()` doesn't serialize properly with savepoints, causing all steps to get the same group.

**Bad** (all steps get same group):
```php
DB::transaction(function () {
    foreach ($items as $item) {
        Step::create([...]);  // All get 'alpha'
    }
});
```

**Good** (proper round-robin):
```php
foreach ($items as $item) {
    Step::create([...]);  // Each gets different group
}
```

Each `Step::create()` runs its own `getNextGroup()` transaction independently, ensuring proper serialization and group rotation.

## Key Traits

### BaseQueueableJob
**Location**: `packages/martingalian/core/src/Abstracts/BaseQueueableJob.php`

**Concerns Used**:
- `HandlesStepExceptions` - Exception handling logic
- `FormatsStepResult` - Result formatting
- `HandlesStepLifecycle` - Lifecycle management

**Core Methods**:
- `handle()` - Main entry point (final, cannot override)
- `compute()` - Abstract, child classes implement
- `retryJob()` - Transition to Pending for retry
- `reportAndFail()` - Transition to Failed with error
- `handleException()` - Centralized exception handling

**Flow**:
```php
public final function handle(): void
{
    try {
        $this->prepareJobExecution();

        if ($this->isInConfirmationMode()) {
            $this->handleConfirmationMode();
            return;
        }

        if ($this->shouldExitEarly()) {
            return;
        }

        $this->executeJobLogic(); // Calls compute()

        if ($this->needsVerification()) {
            return;
        }

        $this->finalizeJobExecution();
    } catch (\Throwable $e) {
        $this->handleException($e);
    }
}
```

### HandlesStepExceptions
**Location**: `packages/martingalian/core/src/Concerns/BaseQueueableJob/HandlesStepExceptions.php`

**Methods**:
- `handleException(Throwable $e)` - Main exception router
- `shouldRetryException(Throwable $e)` - Check if retryable
- `shouldIgnoreException(Throwable $e)` - Check if ignorable
- `resolveExceptionIfPossible(Throwable $e)` - Custom recovery
- `retryJobWithBackoff()` - Retry with delay
- `completeAndIgnoreException()` - Mark as Completed despite error
- `logExceptionToStep(Throwable $e)` - Record error in step
- `reportAndFail(Throwable $e)` - Send notification and fail

**Exception Types**:
- `JustResolveException` - Skip to resolution without fail
- `JustEndException` - End without fail/complete
- `MaxRetriesReachedException` - Exhausted retries
- `NonNotifiableException` - Suppress notification

## Commands

### core:dispatch-steps
**Location**: `packages/martingalian/core/src/Console/DispatchStepsCommand.php`

**Purpose**: Scheduled command that dispatches `ProcessGroupTickJob` for each named group

**Usage**:
```bash
php artisan core:dispatch-steps
```

**Schedule** (in `routes/console.php`):
```php
Schedule::command('core:dispatch-steps')->everySecond();
```

**How It Works**:
```php
public function handle(): int
{
    // Dispatch ProcessGroupTickJob for each of the 10 named groups
    $groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];

    foreach ($groups as $group) {
        ProcessGroupTickJob::dispatch($group);
    }

    return self::SUCCESS;
}
```

### ProcessGroupTickJob
**Location**: `packages/martingalian/core/src/Jobs/ProcessGroupTickJob.php`

**Purpose**: Process one dispatcher tick for a specific group. Runs on 'default' queue.

**Key Behavior**:
- Acquires per-group lock via `StepsDispatcher::startDispatch($group)`
- Executes all dispatcher phases (state management + dispatch)
- Releases lock via `StepsDispatcher::endDispatch($progress, $group)`
- Multiple groups can process in parallel (10 concurrent ticks possible)

```php
class ProcessGroupTickJob implements ShouldQueue
{
    public $queue = 'default';

    public function __construct(
        public string $group
    ) {}

    public function handle(): void
    {
        StepDispatcher::dispatch($this->group);
    }
}
```

**Parallelism Architecture**:
```
cron (every second)
    └── core:dispatch-steps
        ├── ProcessGroupTickJob(alpha)  → Worker A
        ├── ProcessGroupTickJob(beta)   → Worker B
        ├── ProcessGroupTickJob(gamma)  → Worker C
        ├── ProcessGroupTickJob(delta)  → Worker A
        ├── ProcessGroupTickJob(epsilon)→ Worker B
        ├── ProcessGroupTickJob(zeta)   → Worker C
        ├── ProcessGroupTickJob(eta)    → Worker A
        ├── ProcessGroupTickJob(theta)  → Worker B
        ├── ProcessGroupTickJob(iota)   → Worker C
        └── ProcessGroupTickJob(kappa)  → Worker A
```

Each group processes independently, enabling 10x workflow parallelism.

## Configuration

### Dispatch Groups (Workflow-Level Parallelism)

**Purpose**: Steps are assigned to named groups for parallel processing. Each group has its own dispatcher tick, allowing 10 workflows to run simultaneously.

**10 Named Groups**:
```
alpha, beta, gamma, delta, epsilon, zeta, eta, theta, iota, kappa
```

**Group Assignment Rules** (in StepObserver):
1. **Root step** (no parent) → Assigned via round-robin from `StepsDispatcher::getNextGroup()`
2. **Child step** → Inherits parent's group via `child_block_uuid` lookup
3. **All descendants** → Share the root step's group for coherent workflow processing

**Database Seeding** (in seeder):
```php
$groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
foreach ($groups as $group) {
    StepsDispatcher::firstOrCreate(
        ['group' => $group],
        ['can_dispatch' => true, 'last_selected_at' => null]
    );
}
```

**Why 10 Groups?**
- Provides sufficient parallelism for multi-workflow processing
- Each group processes independently via `ProcessGroupTickJob`
- Named groups are human-readable for debugging
- Round-robin ensures even distribution across groups

### Queue System
**Location**: `packages/martingalian/core/src/Observers/StepObserver.php`

Valid queue names for step execution:
```php
$validQueues = [
    'default',      // Standard queue for most operations
    'priority',     // High priority queue (auto-assigned when step priority='high')
    'candles',      // Dedicated queue for candle data fetching (taapi:store-candles)
    'indicators',   // Dedicated queue for indicator calculations (cronjobs:conclude-symbols-direction)
    mb_strtolower(gethostname()) // Hostname-based queue for server-specific tasks
];
```

**Queue Assignment Logic** (in StepObserver):
- Steps with `priority='high'` are automatically routed to 'priority' queue
- Invalid queue names fallback to 'default'
- StoreCandlesCommand uses 'candles' queue
- ConcludeSymbolsDirectionCommand uses 'indicators' queue

### Horizon Queues
**Location**: `config/horizon.php`

Maps queues to workers:
```php
'queue' => ['default', 'priority', 'candles', 'indicators']
```

## Database Schema

### steps
```sql
id, uuid
parent_step_id (nullable, foreign key to steps.id)
relatable_type, relatable_id (polymorphic)
action (varchar - job class name)
arguments (json)
dispatch_group (varchar)
state (varchar - state class name)
dispatch_after (datetime)
started_at, completed_at (datetime)
error_message, error_stack_trace (text)
response (json)
created_at, updated_at
```

**Indexes**:
- `state` - for dispatcher queries
- `parent_step_id` - for parent-child relationships
- `dispatch_after` - for delayed execution
- `dispatch_group` - for group filtering

### steps_dispatcher
```sql
id
group (varchar) -- Named group: alpha, beta, gamma, delta, epsilon, zeta, eta, theta, iota, kappa
can_dispatch (boolean) -- Per-group lock flag
current_tick_id (bigint, nullable) -- FK to steps_dispatcher_ticks.id
last_tick_completed (datetime) -- When last tick finished
last_selected_at (datetime(6)) -- Microsecond precision for round-robin ordering
created_at, updated_at
```

**Indexes**:
- `group` (unique) - One row per named group
- `last_selected_at` - For round-robin ordering

### steps_dispatcher_ticks
```sql
id
dispatch_group (varchar)
steps_dispatched (integer)
duration_ms (integer)
created_at
```

## Testing

### Feature Tests
**Location**: `tests/Feature/StepDispatcher/`

**Key Test Files**:
- `StepObserverGroupAssignmentTest.php` - Group assignment and round-robin distribution
- `StepDispatcherTest.php` - Core dispatcher logic
- `StepStateTransitionTest.php` - State machine transitions

### StepObserverGroupAssignmentTest.php

**Group Inheritance Tests**:
- `root step gets a group from steps_dispatcher via round-robin`
- `root step with explicit group keeps that group`
- `child step inherits parent group`
- `grandchild step inherits root group (2 levels deep)`
- `5 levels deep all inherit root group`
- `multiple children in same block all inherit parent group`
- `parallel children at same index all inherit parent group`
- `group is preserved on step update`

**Round-Robin Distribution Tests**:
- `round-robin cycles through groups` - Verifies 10 consecutive steps get all 10 groups
- `many steps distribute evenly across all 10 groups` - 30 steps → 3 per group
- `100 steps distribute evenly across all 10 groups` - 100 steps → 10 per group
- `steps created without transaction wrapper distribute correctly`
- `steps created inside collection each() distribute correctly`
- `nested transaction with savepoints breaks round-robin distribution` - Documents known issue

**Index Assignment Tests**:
- `orphan step defaults to index 1`
- `orphan step with index 0 gets index 1`
- `step with explicit index keeps that index`

**Test Setup** (beforeEach):
```php
beforeEach(function (): void {
    // Clean up steps from previous tests
    Step::query()->delete();

    // Seed all 10 groups for round-robin selection
    $groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    foreach ($groups as $group) {
        StepsDispatcher::firstOrCreate(
            ['group' => $group],
            ['can_dispatch' => true, 'last_selected_at' => null]
        );
    }
    // Reset for predictable round-robin
    StepsDispatcher::query()->update(['last_selected_at' => null]);
});
```

### Integration Tests
**Location**: `tests/Integration/StepDispatcher/`

**Key Tests**:
- Parent-child dependency enforcement
- State transition validation
- Failure cascading
- Skip cascading
- Retry logic
- Dispatch group isolation

### Unit Tests
**Location**: `tests/Unit/StepDispatcher/`

**Key Tests**:
- State machine transitions
- Exception handling
- Job retry logic

## Monitoring

### Step Dispatcher Dashboard (Real-time UI)
**Location**: `/step-dispatcher` route
**Controller**: `App\Http\Controllers\StepDispatcherController`
**View**: `resources/views/step-dispatcher.blade.php`

**Purpose**: Real-time monitoring dashboard for step processing across all worker servers with auto-refresh every 3 seconds.

**Features**:
- **Global Metrics** (all hostnames combined):
  - Total steps by state: Pending, Child Pending, Dispatched, Running, Child Running, Completed, Failed, Skipped, Throttled
  - Circuit breaker toggle: Enable/disable `can_dispatch_steps` via UI switch
  - Active step classes table (Running, Child Running, Throttled)
  - Auto-refresh every 3 seconds

- **Per-Hostname Metrics** (worker1, worker2, worker3, ingestion):
  - Individual server statistics
  - Active step classes for each server
  - Collapsible sections for each hostname
  - Visual indicators for server status

- **Step Classes Tables**:
  - **Compact 4-column layout**: Class, Running, Child Running, Throttled
  - **Active-only filtering**: Only shows classes where `running > 0 OR child_running > 0 OR dispatched > 0`
  - **Dynamic rendering**: Classes appear/disappear automatically without manual page refresh
  - **Color-coded metrics**: Cyan (Running), Purple (Child Running), Orange (Throttled)
  - **Applied to**: Both global step classes and per-hostname step classes

**Technical Implementation**:
- **Auto-refresh**: JavaScript `setInterval` polling API endpoint every 3 seconds
- **API Endpoint**: `GET /step-dispatcher/api` (returns JSON metrics)
- **Dynamic HTML rebuild**: Complete section regeneration on each refresh (handles new classes appearing)
- **Toggle synchronization**: Strict boolean comparison (`=== true`) ensures correct switch state
- **Smart DOM initialization**: Handles both `DOMContentLoaded` and already-loaded states
- **MD5 hashing**: Generates unique identifiers for class rows

**SQL Queries** (in `StepDispatcherController::getMetrics()`):
```php
// Global step counts by state
DB::table('steps')
    ->select('state', DB::raw('count(*) as count'))
    ->groupBy('state')
    ->get();

// Per-hostname step counts
DB::table('steps')
    ->select('queue as hostname', 'state', DB::raw('count(*) as count'))
    ->groupBy('queue', 'state')
    ->get();

// Global step class statistics
DB::table('steps')
    ->select('action as class', 'state', DB::raw('count(*) as count'))
    ->groupBy('action', 'state')
    ->get();

// Per-hostname class statistics
DB::table('steps')
    ->select('queue as hostname', 'action as class', 'state', DB::raw('count(*) as count'))
    ->groupBy('queue', 'action', 'state')
    ->get();
```

**Circuit Breaker Toggle**:
- POST to `/step-dispatcher/toggle` with `{ enabled: true/false }`
- Updates `martingalian.can_dispatch_steps` column
- Returns new state for UI synchronization
- Toggle reflects current state on page load and after each refresh

**UI Layout**:
- Glass morphism design with gradient background
- Responsive grid layout (mobile, tablet, desktop)
- Tailwind CSS utility classes
- Collapsible sections with chevron animations
- Version badge (v1.0.0) displayed in header

**Performance**:
- Efficient SQL queries with grouping
- No N+1 queries
- Minimal DOM manipulation (complete rebuild approach)
- ~3-second refresh interval balances freshness vs server load

**Use Cases**:
- Monitor step processing in real-time across all servers
- Identify bottlenecks (classes with high Running/Throttled counts)
- Verify circuit breaker state before deployments
- Track step distribution across worker servers
- Debug step processing issues (see which classes are active)

### Horizon Dashboard
- View queued steps by dispatch group
- Monitor job failures
- Track throughput

### Logs
**Channel**: `dispatcher`
**Location**: `storage/logs/dispatcher.log`

**Log Format**:
```
[TICK START] Group: default | Time: 14:23:45.123456
[LOCK ACQUIRED] Starting dispatch cycle
[Step 0] skipAllChildStepsOnParentAndChildSingleStep: NO | Duration: 2.34ms
[Step 1] cascadeCancelledSteps: NO | Duration: 1.23ms
...
[Step 7] pickDispatchableSteps: Found 5 steps | Duration: 15.67ms
[Step 8] dispatchSteps: Dispatched 5 steps | Duration: 8.90ms
[TICK END] Total: 45.12ms | Dispatched: 5
```

## Performance Considerations

### Optimization 1: Early Returns
- Each dispatcher step returns early if it performs work
- Prevents cascading effects in same tick
- Reduces lock contention

### Optimization 2: Pessimistic Locking
- Uses DB-level locks via `StepsDispatcher`
- Prevents race conditions across servers

### Optimization 3: Group Isolation
- Each server handles specific groups
- Distributes load across servers
- Reduces query complexity

### Optimization 4: Index Coverage
- All dispatcher queries use indexed columns
- Fast step selection (< 10ms for 10k+ steps)

### Optimization 5: Batch Processing
- Dispatcher processes multiple steps per tick
- Configured limit per group

## Common Patterns

### Creating a Step Chain
```php
$parentStep = Step::create([
    'relatable_id' => $account->id,
    'relatable_type' => Account::class,
    'action' => SyncAccountJob::class,
    'arguments' => [],
    'dispatch_group' => 'default',
    'state' => Pending::class,
]);

$childStep = Step::create([
    'parent_step_id' => $parentStep->id,
    'relatable_id' => $account->id,
    'relatable_type' => Account::class,
    'action' => UpdateBalanceJob::class,
    'arguments' => ['currency' => 'USDT'],
    'dispatch_group' => 'default',
    'state' => Pending::class,
]);
```

### Implementing a Job
```php
use Martingalian\Core\Abstracts\BaseQueueableJob;

class MyJob extends BaseQueueableJob
{
    protected function compute()
    {
        // Your logic here

        // Retry on failure
        if ($shouldRetry) {
            $this->retryJob(now()->addMinutes(5));
            return;
        }

        // Store response data
        $this->step->update([
            'response' => ['result' => 'success'],
        ]);
    }

    protected function retryException(Throwable $e): bool
    {
        // Custom retry logic
        return $e instanceof TemporaryException;
    }
}
```

### Manual State Transitions
```php
// Mark as completed
$step->state->transitionTo(Completed::class);

// Mark as failed
$step->state->transitionTo(Failed::class);

// Mark as skipped
$step->state->transitionTo(Skipped::class);
```

## Circuit Breaker Pattern

### Overview

The circuit breaker is a global kill switch that prevents the StepDispatcher from dispatching new jobs while allowing currently running jobs to complete. This enables graceful Horizon restarts and code deployments without orphaning steps.

**Purpose**: Prevent orphaned steps during Horizon restarts or deployments by ensuring no new jobs are dispatched while allowing active jobs to drain naturally.

### Database Configuration

**Column**: `martingalian.can_dispatch_steps` (boolean, default: `true`)

```sql
-- Disable circuit breaker (stop new dispatches)
UPDATE martingalian SET can_dispatch_steps = false;

-- Enable circuit breaker (resume normal operation)
UPDATE martingalian SET can_dispatch_steps = true;
```

### How It Works

**Dispatcher Check** (runs after all state management phases, before pending step dispatch):

```php
// Location: StepDispatcher.php (after progress 6)
$martingalian = Martingalian::first();
if (! $martingalian || ! $martingalian->can_dispatch_steps) {
    log_step('dispatcher', '🔴 CIRCUIT BREAKER: Step dispatching is DISABLED globally');
    log_step('dispatcher', '→ All state management phases completed successfully');
    log_step('dispatcher', '→ Skipping pending step dispatch phase (circuit breaker active)');

    return; // Skip only the dispatch phase
}
```

**When Disabled** (can_dispatch_steps = false):
- ✅ Dispatcher acquires lock (prevents race conditions)
- ✅ All state management phases execute normally:
  - ✅ Skip children processing (Skipped parents → Skipped children)
  - ✅ Cascade cancellations (Cancelled parents → Cancelled children)
  - ✅ Promote resolve-exception steps (Failed blocks → Pending exception handlers)
  - ✅ Transition parents to Failed (All children failed → Parent failed)
  - ✅ Cascade failures to children (Failed parents → Failed children)
  - ✅ Transition parents to Completed (All children concluded → Parent completed)
- ✅ Circuit breaker check fails at progress 6
- ✅ Releases lock and returns
- ❌ **Skips ONLY the pending step dispatch phase (Pending → Dispatched)**
- ✅ Running jobs continue normally
- ✅ No new jobs dispatched

**When Enabled** (default):
- ✅ Normal operation resumes
- ✅ All dispatch phases execute
- ✅ New jobs dispatched as usual

### Safe Restart Detection

**Method**: `StepDispatcher::canSafelyRestart(?string $group = null): bool`

Returns `true` only when ALL conditions are met:
1. Circuit breaker is **DISABLED** (`can_dispatch_steps = false`)
2. No steps in `Running` state
3. No steps in `Dispatched` state

**Usage**:
```php
// Check if safe to restart Horizon
if (StepDispatcher::canSafelyRestart()) {
    // ✅ Safe to restart
    php artisan horizon:terminate
} else {
    // ❌ Wait for jobs to complete
}
```

**Optional Group Filtering**:
```php
// Check specific dispatch group
StepDispatcher::canSafelyRestart('high');
```

### Deployment Workflow

**Complete Safe Deployment Process**:

```bash
# 1. Disable circuit breaker
php artisan tinker
>>> Martingalian::first()->update(['can_dispatch_steps' => false])
=> true

# 2. Wait for active jobs to drain
>>> StepDispatcher::canSafelyRestart()
=> false  # Wait...

# Keep checking every 10-30 seconds
>>> StepDispatcher::canSafelyRestart()
=> true  # ✅ Safe to restart!

# 3. Restart Horizon safely
php artisan horizon:terminate

# 4. Deploy code changes (if needed)
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

# 5. Re-enable circuit breaker
php artisan tinker
>>> Martingalian::first()->update(['can_dispatch_steps' => true])
=> true

# 6. Verify dispatcher resumed
tail -f storage/logs/dispatcher.log
# Look for: "[TICK START]" messages
```

### Common Use Cases

**1. Code Deployment**:
- Disable circuit breaker
- Wait for jobs to complete
- Deploy new code
- Restart Horizon
- Re-enable circuit breaker

**2. Horizon Configuration Changes**:
- Disable circuit breaker
- Wait for jobs to complete
- Update `config/horizon.php`
- Restart Horizon
- Re-enable circuit breaker

**3. Emergency Stop**:
- Disable circuit breaker immediately
- Investigate issues while jobs complete
- Fix problems
- Re-enable when ready

**4. Maintenance Window**:
- Disable circuit breaker
- Let system drain completely
- Perform database maintenance
- Re-enable when done

### Monitoring

**Dispatcher Logs** (`storage/logs/dispatcher.log`):

```
[TICK START] Group: default | Time: 14:23:45.123456
[LOCK ACQUIRED] Starting dispatch cycle
🔴 CIRCUIT BREAKER: Step dispatching is DISABLED globally
[TICK SKIPPED] Circuit breaker active - can_dispatch_steps = false
```

**Database Check**:
```sql
-- Check circuit breaker status
SELECT can_dispatch_steps FROM martingalian LIMIT 1;

-- Check active jobs
SELECT COUNT(*) FROM steps WHERE state LIKE '%Running%';
SELECT COUNT(*) FROM steps WHERE state LIKE '%Dispatched%';
```

### Why This Prevents Orphaned Steps

**The Problem**: Without circuit breaker, Horizon could be killed mid-transaction during state transitions, leaving steps stuck in `Running` state with no active process.

**The Solution**: Circuit breaker ensures:
1. **No new dispatches** → No new jobs enter the system (Pending → Dispatched blocked)
2. **State management continues** → Parents complete, failures cascade, system reaches clean final state
3. **Jobs drain naturally** → Active jobs complete normally
4. **Safe restart point** → `canSafelyRestart()` confirms no active jobs
5. **No orphaned steps** → All transitions complete before Horizon restart

**Why State Management Must Continue**:
- Running jobs may complete and their parent steps need to transition to Completed
- Failed jobs may cascade their failures to children
- The system needs to "settle" into terminal states for all active work
- Freezing state management would leave parents stuck in Running even after children complete

**Real Incident** (2025-11-23):
- Horizon crashed at 00:10:58 during Running → Pending transition
- 10 steps orphaned in Running state for 10+ hours
- Circuit breaker prevents this by allowing clean drainage while blocking new dispatches

See `Problem.md` for detailed root cause analysis.

### Performance Impact

**Negligible**:
- Single database read per dispatcher tick
- Cached in Eloquent model instance
- Adds ~1-2ms to tick duration
- No impact when enabled (default state)

### Important Notes

⚠️ **Remember to re-enable** after deployment! Forgot to re-enable? Your steps won't dispatch.

✅ **Safe to leave disabled temporarily** - Running jobs continue, no data loss

❌ **Don't force-restart Horizon** when circuit breaker disabled - defeats the purpose

✅ **Use `canSafelyRestart()`** - Don't guess when it's safe
