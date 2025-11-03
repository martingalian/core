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
- `dispatch_group` (queue group: 'default', 'low', 'high')
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

**Purpose**: Manages dispatcher locks and active groups per server

**Key Methods**:
- `startDispatch(?string $group)` - Acquire lock, return true if successful
- `endDispatch(?string $group)` - Release lock
- `getDispatchGroup()` - Get random active dispatch group

**Fields**:
- `dispatch_group` (which group this server handles)
- `is_dispatching` (lock flag)
- `started_at`, `ended_at`

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
2. **Step 0**: `skipAllChildStepsOnParentAndChildSingleStep()` - Mark children as Skipped if parent is Skipped
3. **Step 1**: `cascadeCancelledSteps()` - Cascade Cancelled state to children
4. **Step 2**: `promoteResolveExceptionSteps()` - Handle JustResolveException recovery
5. **Step 3**: `transitionParentsToFailed()` - Mark parents Failed if all children failed
6. **Step 4**: `cascadeFailureToChildren()` - Mark children Failed if parent is Failed
7. **Step 5**: `transitionParentStepsToCompleted()` - Mark parents Completed if all children concluded
8. **Step 6**: `retryExhaustedSteps()` - Transition to Failed if max retries reached
9. **Step 7**: `pickDispatchableSteps()` - Find Pending steps ready to dispatch
10. **Step 8**: `dispatchSteps()` - Dispatch jobs to queue

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

## Key Traits

### BaseQueueableJob
**Location**: `packages/martingalian/core/src/Abstracts/BaseQueueableJob.php`

**Concerns Used**:
- `HandlesStepExceptions` - Exception handling logic
- `ManagesStepDuration` - Timing tracking
- `InteractsWithStepState` - State transitions

**Core Methods**:
- `handle()` - Main entry point (final, cannot override)
- `perform()` - Abstract, child classes implement
- `retryJob()` - Transition to Pending for retry
- `reportAndFail()` - Transition to Failed with error
- `handleException()` - Centralized exception handling

**Flow**:
```php
public final function handle(): void
{
    $this->initializeDuration();
    $this->step->state->transitionTo(Running::class);

    try {
        $this->perform(); // Child implements
        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
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

### steps:dispatch
**Location**: `app/Console/Commands/StepsDispatchCommand.php`

**Purpose**: Scheduled command that runs every second

**Usage**:
```bash
php artisan steps:dispatch              # All groups
php artisan steps:dispatch --group=high # Specific group
```

**Schedule** (in `routes/console.php`):
```php
Schedule::command('steps:dispatch')->everySecond();
```

## Configuration

### Dispatch Groups
**Location**: `config/martingalian.php`

```php
'dispatch_groups' => [
    'default' => ['weight' => 70],
    'low'     => ['weight' => 20],
    'high'    => ['weight' => 10],
]
```

**Weight**: Probability of group selection for server assignment

### Horizon Queues
**Location**: `config/horizon.php`

Maps dispatch groups to queue names:
```php
'queue' => ['default', 'low', 'high']
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

### steps_dispatchers
```sql
id
dispatch_group (varchar)
is_dispatching (boolean)
started_at, ended_at (datetime)
```

### steps_dispatcher_ticks
```sql
id
dispatch_group (varchar)
steps_dispatched (integer)
duration_ms (integer)
created_at
```

## Testing

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
    protected function perform(): void
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
