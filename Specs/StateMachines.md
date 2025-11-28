# State Machines

## Overview
State machine implementations for managing workflow execution (Steps), order lifecycle (Orders), and position lifecycle (Positions). Provides structured state transitions with validation, guards, and side effects.

## Architecture

### State Machine Types

1. **Step State Machine** - Uses Spatie ModelStates package
   - Complex workflow with 9 states
   - Explicit transition classes
   - Guards and side effects

2. **Order Status Machine** - Simple string-based
   - 6 states reflecting exchange order status
   - Updated from exchange API responses

3. **Position Status Machine** - Simple string-based
   - 3 states (OPEN, CLOSED, LIQUIDATED)
   - Managed by position lifecycle jobs

## Step State Machine

### Overview
**Package**: Spatie ModelStates
**Model**: `Martingalian\Core\Models\Step`
**Abstract State**: `Martingalian\Core\Abstracts\StepStatus`

**Purpose**: Manages job execution workflow with precise state tracking

---

### States

#### Pending
**Class**: `Martingalian\Core\States\Pending`
**Value**: `"pending"`
**Meaning**: Step created, waiting for dispatch

**When**:
- Step just created via `steps:dispatch` command
- Parent steps not yet completed
- Waiting for scheduled `dispatch_after` time

**Query**:
```php
Step::where('state', Pending::class)->get();
// OR
Step::pending()->get(); // Via scope
```

---

#### Dispatched
**Class**: `Martingalian\Core\States\Dispatched`
**Value**: `"dispatched"`
**Meaning**: Step dispatched to queue, not yet picked up by worker

**When**:
- Step dispatched by StepsDispatcher
- Waiting in queue for available worker

**Transition From**: Pending

---

#### Running
**Class**: `Martingalian\Core\States\Running`
**Value**: `"running"`
**Meaning**: Step currently executing on worker

**When**:
- Job handler executing `handle()` method
- Started at timestamp recorded

**Transition From**: Pending, Dispatched, Running (self-transition for updates)

**Side Effects**:
- Sets `started_at` timestamp
- Updates `hostname` with worker hostname

---

#### Completed
**Class**: `Martingalian\Core\States\Completed`
**Value**: `"completed"`
**Meaning**: Step finished successfully

**When**:
- Job completed without exceptions
- All business logic executed successfully
- No errors encountered

**Transition From**: Running

**Side Effects**:
- Sets `completed_at` timestamp
- Calculates `duration` (completed_at - started_at)
- Stores `response` (job return value)

**Query**:
```php
$completedSteps = Step::where('state', Completed::class)->get();
```

---

#### Failed
**Class**: `Martingalian\Core\States\Failed`
**Value**: `"failed"`
**Meaning**: Step failed with unrecoverable error

**When**:
- Exception thrown and max retries reached
- Non-retryable error encountered
- Validation failed

**Transition From**: Pending, Dispatched, Running

**Side Effects**:
- Sets `error_message` (exception message)
- Sets `error_stack_trace` (full stack trace)
- Sets `completed_at` timestamp
- May trigger notification (if not throttled)

**Query**:
```php
$failedSteps = Step::where('state', Failed::class)->get();
```

---

#### Cancelled
**Class**: `Martingalian\Core\States\Cancelled`
**Value**: `"cancelled"`
**Meaning**: Step cancelled before execution

**When**:
- User-initiated cancellation
- Parent step failed/cancelled
- System shutdown requested

**Transition From**: Pending, Dispatched

**Query**:
```php
$cancelledSteps = Step::where('state', Cancelled::class)->get();
```

---

#### Skipped
**Class**: `Martingalian\Core\States\Skipped`
**Value**: `"skipped"`
**Meaning**: Step intentionally bypassed

**When**:
- Pre-conditions not met (but not an error)
- Feature flag disabled
- Already processed (idempotency check)

**Transition From**: Pending, Running

**Example**:
```php
// In job handle()
if ($this->order->status === 'FILLED') {
    // Order already filled, skip
    $this->step->state->transitionTo(Skipped::class);
    return;
}
```

---

#### Stopped
**Class**: `Martingalian\Core\States\Stopped`
**Value**: `"stopped"`
**Meaning**: Step stopped mid-execution (not failed, not completed)

**When**:
- Job manually stopped by admin
- Timeout reached (but want to preserve partial work)
- Soft failure (want to investigate before retrying)

**Transition From**: Running

**Difference from Failed**: Stopped is intentional/controlled, Failed is unexpected

---

#### NotRunnable
**Class**: `Martingalian\Core\States\NotRunnable`
**Value**: `"not_runnable"`
**Meaning**: Step cannot be executed (temporarily)

**When**:
- Missing required dependencies
- Account disabled
- API credentials invalid

**Transition From**: (Initial state, can transition to Pending when resolved)

**Transition To**: Pending (when issue resolved)

---

### State Diagram

```
┌─────────────┐
│ NotRunnable │
└──────┬──────┘
       │ (issue resolved)
       ▼
┌─────────┐      ┌───────────┐      ┌─────────┐      ┌───────────┐
│ Pending ├─────►│Dispatched ├─────►│ Running ├─────►│ Completed │
└────┬────┘      └─────┬─────┘      └────┬────┘      └───────────┘
     │                 │                   │
     │                 │                   ├────► Failed
     │                 │                   │
     ├────► Cancelled◄─┘                   ├────► Skipped
     │                                     │
     └────► Failed                         ├────► Stopped
                                           │
                                           └────► Pending (retry)
```

---

### Transitions

#### PendingToDispatched
**Location**: `Martingalian\Core\Transitions\PendingToDispatched`
**Trigger**: StepsDispatcher picks up step for dispatch

**Logic**:
```php
public function handle(): Step
{
    $this->step->state = new Dispatched($this->step);
    $this->step->save();

    return $this->step;
}
```

---

#### PendingToRunning
**Location**: `Martingalian\Core\Transitions\PendingToRunning`
**Trigger**: Worker starts executing step (skips Dispatched state)

**Logic**:
```php
public function handle(): Step
{
    $this->step->state = new Running($this->step);
    $this->step->started_at = now();
    $this->step->hostname = gethostname();
    $this->step->save();

    return $this->step;
}
```

---

#### RunningToCompleted
**Location**: `Martingalian\Core\Transitions\RunningToCompleted`
**Trigger**: Job completes successfully

**Logic**:
```php
public function handle(): Step
{
    $this->step->state = new Completed($this->step);
    $this->step->completed_at = now();
    $this->step->duration = $this->step->started_at->diffInMilliseconds($this->step->completed_at);
    $this->step->save();

    return $this->step;
}
```

---

#### RunningToFailed
**Location**: `Martingalian\Core\Transitions\RunningToFailed`
**Trigger**: Job throws unrecoverable exception

**Logic**:
```php
public function handle(): Step
{
    $this->step->state = new Failed($this->step);
    $this->step->completed_at = now();
    $this->step->duration = $this->step->started_at->diffInMilliseconds($this->step->completed_at);
    // error_message and error_stack_trace set separately
    $this->step->save();

    return $this->step;
}
```

---

#### RunningToPending
**Location**: `Martingalian\Core\Transitions\RunningToPending`
**Trigger**: Job needs to retry (transient error, rate limit)

**Logic**:
```php
public function handle(): Step
{
    $this->step->state = new Pending($this->step);
    $this->step->retries++;
    $this->step->dispatch_after = now()->addSeconds($backoffSeconds);
    $this->step->save();

    return $this->step;
}
```

---

#### RunningToRunning
**Location**: `Martingalian\Core\Transitions\RunningToRunning`
**Trigger**: Long-running job updates progress (heartbeat)

**Logic**:
```php
public function handle(): Step
{
    // Self-transition to update step metadata
    $this->step->response = $progressData; // e.g., percentage
    $this->step->save();

    return $this->step;
}
```

---

### Guards and Validation

**Pre-Transition Validation**:
```php
// Before transitioning, check conditions
if (!$step->state->canTransitionTo(Completed::class)) {
    throw new InvalidStateTransition("Cannot complete step in {$step->state} state");
}
```

**Common Guards**:
1. **Parent Not Running**: Cannot transition to Running if parent is not Running
2. **Previous Index Not Concluded**: Cannot run if previous index step not completed
3. **Max Retries**: Cannot transition to Pending if max retries reached

**Example Guard in Step Model**:
```php
public function previousIndexIsConcluded(): bool
{
    // Check if previous step (index - 1) is completed
    $previousSteps = self::where('block_uuid', $this->block_uuid)
        ->where('index', $this->index - 1)
        ->get();

    return $previousSteps->every(
        fn($step) => in_array(get_class($step->state), [Completed::class, Skipped::class])
    );
}
```

---

### State Queries

**Terminal States** (no further transitions):
```php
Step::terminalStepStates();
// Returns: [Completed, Skipped, Cancelled, Failed, Stopped]

$terminalSteps = Step::whereIn('state', Step::terminalStepStates())->get();
```

**Concluded States** (successful termination):
```php
Step::concludedStepStates();
// Returns: [Completed, Skipped]

$concludedSteps = Step::whereIn('state', Step::concludedStepStates())->get();
```

**Failed States** (unsuccessful termination):
```php
Step::failedStepStates();
// Returns: [Failed, Stopped]

$failedSteps = Step::whereIn('state', Step::failedStepStates())->get();
```

**Dispatchable Scope**:
```php
$dispatchableSteps = Step::dispatchable()->get();
// WHERE state = Pending AND type = 'default'
```

---

## Order Status Machine

### Overview
**Model**: `Martingalian\Core\Models\Order`
**Type**: String-based status field (not Spatie ModelStates)
**Source of Truth**: Exchange API responses

---

### States

#### NEW
**Meaning**: Order placed, awaiting exchange processing
**When**: Just created via API, not yet matched

---

#### PARTIALLY_FILLED
**Meaning**: Order partially executed
**When**: Some quantity filled, remaining on order book
**Data**:
- `filled_quantity` - Amount executed so far
- `average_fill_price` - Weighted average of fills

---

#### FILLED
**Meaning**: Order completely executed
**When**: Full quantity traded
**Data**:
- `filled_quantity` = `quantity`
- `average_fill_price` - Final average price
- `commission` - Total fees paid

---

#### CANCELED
**Meaning**: Order cancelled (not executed)
**When**:
- User-initiated cancellation
- Exchange rejected
- IOC/FOK not immediately filled

---

#### REJECTED
**Meaning**: Exchange rejected order
**When**:
- Invalid parameters
- Insufficient balance
- Symbol not tradeable
- Leverage too high

---

#### EXPIRED
**Meaning**: Order expired without fill
**When**:
- Time-in-force expired
- Post-only order would have matched immediately

---

### State Diagram

```
┌─────┐      ┌───────────────────┐      ┌────────┐
│ NEW ├─────►│ PARTIALLY_FILLED  ├─────►│ FILLED │
└──┬──┘      └─────────┬─────────┘      └────────┘
   │                   │
   │                   │
   ├────► CANCELED ◄───┤
   │
   ├────► REJECTED
   │
   └────► EXPIRED
```

---

### Transitions

**NEW → PARTIALLY_FILLED**:
- Exchange matched part of order
- Update from WebSocket or polling

**NEW → FILLED**:
- MARKET order executed immediately
- LIMIT order filled entirely in one match

**NEW → CANCELED**:
- User cancels pending order
- System cancels (position closed)

**NEW → REJECTED**:
- Exchange validation failed
- Usually immediate (no retry)

**PARTIALLY_FILLED → FILLED**:
- Remaining quantity executed
- Most common path for large orders

**PARTIALLY_FILLED → CANCELED**:
- User cancels partially filled order
- Keep filled portion

**NEW → EXPIRED**:
- FOK order not filled immediately
- GTD order reached expiration time

---

### Order History

**OrderHistory Model**: Records each status change
```php
OrderHistory::create([
    'order_id' => $order->id,
    'status' => 'PARTIALLY_FILLED',
    'filled_quantity' => 0.05,
    'remaining_quantity' => 0.05,
    'average_price' => 45000.00,
    'commission' => 2.25,
    'timestamp' => now(),
    'raw_data' => $apiResponse,
]);
```

**Audit Trail**: Full history of order execution

---

## Position Status Machine

### Overview
**Model**: `Martingalian\Core\Models\Position`
**Type**: String-based status field
**Managed By**: Position lifecycle jobs

---

### States

#### OPEN
**Meaning**: Active position with exposure
**When**: Position opened, not yet closed
**Monitoring**: Continuous price updates, PNL tracking

---

#### CLOSED
**Meaning**: Position exited normally
**When**:
- Take profit hit
- Stop loss hit
- Manual close
- Direction reversal signal

**Data**:
- `closed_at` timestamp
- Final `realized_pnl`

---

#### LIQUIDATED
**Meaning**: Position forcefully closed by exchange (margin depleted)
**When**: Price reached liquidation price
**Severity**: CRITICAL (immediate notification)

**Data**:
- `closed_at` timestamp
- Final `realized_pnl` (usually large negative)
- Account likely disabled (`can_trade` = false)

---

### State Diagram

```
┌──────┐
│ OPEN ├────► CLOSED
└───┬──┘
    │
    └─────► LIQUIDATED
```

---

### Transitions

**OPEN → CLOSED**:
- Exit order filled (TP or SL)
- Manual close order filled
- Strategy exit signal

**OPEN → LIQUIDATED**:
- Margin ratio reached 0%
- Exchange force-liquidates position
- Cannot be prevented once triggered

---

### Position Lifecycle Jobs

**Monitor While OPEN**:
- `SyncPositionJob` - Refresh position data from exchange
- `UpdatePositionPnLJob` - Recalculate unrealized PNL
- `MonitorPositionRiskJob` - Check liquidation distance

**Trigger Close**:
- `ClosePositionJob` - Exit position

**After Liquidation**:
- Disable account
- Send CRITICAL notification
- Log incident for analysis

---

## Common Patterns

### Checking State

**Step**:
```php
if ($step->state->equals(Completed::class)) {
    // Step completed
}

if ($step->state->isOneOf(Completed::class, Skipped::class)) {
    // Step concluded (successfully)
}
```

**Order**:
```php
if ($order->status === 'FILLED') {
    // Order filled
}

if (in_array($order->status, ['FILLED', 'PARTIALLY_FILLED'])) {
    // Order has fills
}
```

**Position**:
```php
if ($position->status === 'OPEN') {
    // Position active
}
```

---

### Transitioning State

**Step** (with Spatie):
```php
$step->state->transitionTo(Completed::class);
// Triggers RunningToCompleted transition
// Runs handle() method
// Saves step automatically
```

**Order** (manual):
```php
$order->update(['status' => 'FILLED']);
// Direct update, no transition class
```

**Position** (manual):
```php
$position->update([
    'status' => 'CLOSED',
    'closed_at' => now(),
]);
```

---

### State Machine Benefits

**Step State Machine** (Complex):
- Explicit transitions with validation
- Side effects encapsulated in transition classes
- Guards prevent invalid transitions
- Audit trail automatic (state column changes)
- Type-safe state checks

**Order/Position Status** (Simple):
- Reflects external system (exchange)
- Simple string comparison
- Updated from API responses
- Less overhead

---

## Testing

### Unit Tests
**Location**: `tests/Unit/States/`
- Valid transitions
- Invalid transitions (expect exception)
- Guard conditions
- Side effects (timestamps, etc.)

**Example**:
```php
it('transitions from Running to Completed', function () {
    $step = Step::factory()->create(['state' => Running::class]);

    $step->state->transitionTo(Completed::class);

    expect($step->state)->toBeInstanceOf(Completed::class);
    expect($step->completed_at)->not->toBeNull();
});

it('prevents invalid transition', function () {
    $step = Step::factory()->create(['state' => Completed::class]);

    expect(fn() => $step->state->transitionTo(Running::class))
        ->toThrow(InvalidStateTransition::class);
});
```

---

### Integration Tests
**Location**: `tests/Integration/States/`
- Full job lifecycle (Pending → Running → Completed)
- Retry flow (Running → Pending → Running → Completed)
- Failure flow (Running → Failed)
- Order status updates from API
- Position closure flow

---

## Future Enhancements
- Account state machine (active, disabled, suspended, liquidated)
- ExchangeSymbol state machine (active, cooling_down, disabled)
- State transition history table (audit log)
- State machine visualization (flowchart generation)
- State-based event listeners (on completed, on failed)
- Automatic retry policies based on state history
