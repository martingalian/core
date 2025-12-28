# State Machines

## Overview

State machine implementations for managing workflow execution (Steps), order lifecycle (Orders), and position lifecycle (Positions). Provides structured state transitions with validation, guards, and side effects.

---

## Architecture

### State Machine Types

| Type | Package | Complexity | Source of Truth |
|------|---------|------------|-----------------|
| Step State Machine | Spatie ModelStates | Complex (9 states, explicit transitions) | Internal |
| Order Status Machine | String-based | Simple (6 states) | Exchange API |
| Position Status Machine | String-based | Simple (3 states) | Position lifecycle jobs |

---

## Step State Machine

### Overview

| Aspect | Details |
|--------|---------|
| Package | Spatie ModelStates |
| Model | `Martingalian\Core\Models\Step` |
| Abstract State | `Martingalian\Core\Abstracts\StepStatus` |
| Purpose | Manages job execution workflow with precise state tracking |

---

### States

#### Pending

| Aspect | Details |
|--------|---------|
| Value | `"pending"` |
| Meaning | Step created, waiting for dispatch |
| When | Step just created, parent not completed, waiting for scheduled time |

---

#### Dispatched

| Aspect | Details |
|--------|---------|
| Value | `"dispatched"` |
| Meaning | Dispatched to queue, not yet picked up by worker |
| Transition From | Pending |

---

#### Running

| Aspect | Details |
|--------|---------|
| Value | `"running"` |
| Meaning | Currently executing on worker |
| Transition From | Pending, Dispatched, Running (self-transition) |
| Side Effects | Sets `started_at`, updates `hostname` |

---

#### Completed

| Aspect | Details |
|--------|---------|
| Value | `"completed"` |
| Meaning | Finished successfully |
| Transition From | Running |
| Side Effects | Sets `completed_at`, calculates `duration`, stores `response` |

---

#### Failed

| Aspect | Details |
|--------|---------|
| Value | `"failed"` |
| Meaning | Failed with unrecoverable error |
| Transition From | Pending, Dispatched, Running |
| Side Effects | Sets `error_message`, `error_stack_trace`, `completed_at` |

---

#### Cancelled

| Aspect | Details |
|--------|---------|
| Value | `"cancelled"` |
| Meaning | Cancelled before execution (never ran) |
| Transition From | Pending, Dispatched |
| Important | Used for steps that never executed - cascaded from parent/sibling failure |

---

#### Skipped

| Aspect | Details |
|--------|---------|
| Value | `"skipped"` |
| Meaning | Intentionally bypassed |
| Transition From | Pending, Running |
| When | Pre-conditions not met, feature flag disabled, idempotency check passed |

---

#### Stopped

| Aspect | Details |
|--------|---------|
| Value | `"stopped"` |
| Meaning | Stopped mid-execution (not failed, not completed) |
| Transition From | Running |
| Difference from Failed | Intentional/controlled vs unexpected |

---

#### NotRunnable

| Aspect | Details |
|--------|---------|
| Value | `"not_runnable"` |
| Meaning | Cannot be executed (temporarily) |
| When | Missing dependencies, account disabled, invalid credentials |
| Transition To | Pending (when issue resolved) |

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

### State Propagation Rules

#### Upward Propagation (Child → Parent)

| Child State | Parent Becomes |
|-------------|----------------|
| Failed | Failed |
| Stopped | Stopped |

- Parent receives `error_message` listing failed/stopped child IDs
- Only affects **Running** parents
- Propagates recursively up the tree

---

#### Downward Propagation (Parent → Children)

| Parent State | Children Become |
|--------------|-----------------|
| Failed | Cancelled |
| Stopped | Cancelled |

- Only affects **Pending** or **Dispatched** children
- Children are Cancelled, not Failed (they never ran)
- Propagates recursively down the tree

---

#### Sibling Propagation

| Sibling State | Higher-Index Siblings Become |
|---------------|------------------------------|
| Failed | Cancelled |
| Stopped | Cancelled |

- Steps at same `block_uuid` are siblings
- If step at `index=2` fails, steps at `index=3,4,5...` are cancelled
- Only affects Pending/Dispatched siblings

---

#### Propagation Example

```
Parent (Running)
├── Child A (index=0) → Completed ✓
├── Child B (index=1) → Failed ✗
│   ├── Grandchild B1 (Pending) → Cancelled (downward)
│   └── Grandchild B2 (Pending) → Cancelled (downward)
└── Child C (index=2, Pending) → Cancelled (sibling)

Result: Parent → Failed (upward from Child B)
```

---

#### Key Distinction: Failed vs Stopped vs Cancelled

| State | Meaning | When Used |
|-------|---------|-----------|
| Failed | Step ran and encountered an error | Exception during execution |
| Stopped | Step ran and was intentionally stopped | Manual stop, timeout, soft failure |
| Cancelled | Step never ran | Cascade from failed/stopped parent or sibling |

---

### Transitions

| Transition | From | To | Side Effects |
|------------|------|----|--------------|
| PendingToDispatched | Pending | Dispatched | None |
| PendingToRunning | Pending | Running | Sets `started_at`, `hostname` |
| RunningToCompleted | Running | Completed | Sets `completed_at`, calculates `duration` |
| RunningToFailed | Running | Failed | Sets `completed_at`, `duration` |
| RunningToPending | Running | Pending | Increments `retries`, sets `dispatch_after` |
| RunningToRunning | Running | Running | Updates `response` (progress heartbeat) |

---

### Guards and Validation

**Common Guards**:

| Guard | Description |
|-------|-------------|
| Parent Not Running | Cannot transition to Running if parent is not Running |
| Previous Index Not Concluded | Cannot run if previous index step not completed |
| Max Retries | Cannot transition to Pending if max retries reached |

---

### State Queries

| Query | States Returned |
|-------|-----------------|
| `terminalStepStates()` | Completed, Skipped, Cancelled, Failed, Stopped |
| `concludedStepStates()` | Completed, Skipped |
| `failedStepStates()` | Failed, Stopped |
| `dispatchable()` scope | Pending steps with type = 'default' |

---

## Order Status Machine

### Overview

| Aspect | Details |
|--------|---------|
| Model | `Martingalian\Core\Models\Order` |
| Type | String-based status field |
| Source of Truth | Exchange API responses |

---

### States

| State | Meaning | When |
|-------|---------|------|
| NEW | Order placed, awaiting processing | Just created via API |
| PARTIALLY_FILLED | Partially executed | Some quantity filled |
| FILLED | Completely executed | Full quantity traded |
| CANCELED | Cancelled (not executed) | User/exchange cancellation |
| REJECTED | Exchange rejected | Invalid parameters, insufficient balance |
| EXPIRED | Expired without fill | Time-in-force expired |

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

| From | To | Trigger |
|------|----|---------|
| NEW | PARTIALLY_FILLED | Exchange matched part of order |
| NEW | FILLED | MARKET order or full LIMIT fill |
| NEW | CANCELED | User/system cancellation |
| NEW | REJECTED | Exchange validation failed |
| NEW | EXPIRED | FOK not filled, GTD expired |
| PARTIALLY_FILLED | FILLED | Remaining quantity executed |
| PARTIALLY_FILLED | CANCELED | User cancels partially filled order |

---

### OrderHistory Model

Records each status change with:
- Previous and new status
- Fill quantities
- Average price
- Commission
- Raw API response

---

## Position Status Machine

### Overview

| Aspect | Details |
|--------|---------|
| Model | `Martingalian\Core\Models\Position` |
| Type | String-based status field |
| Managed By | Position lifecycle jobs |

---

### States

| State | Meaning | When |
|-------|---------|------|
| OPEN | Active position with exposure | Position opened, not yet closed |
| CLOSED | Exited normally | TP/SL hit, manual close, direction reversal |
| LIQUIDATED | Forcefully closed by exchange | Margin depleted |

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

| From | To | Trigger |
|------|----|---------|
| OPEN | CLOSED | Exit order filled, manual close |
| OPEN | LIQUIDATED | Margin ratio reached 0%, exchange force-liquidates |

---

### Position Lifecycle Jobs

| Phase | Jobs |
|-------|------|
| While OPEN | SyncPositionJob, UpdatePositionPnLJob, MonitorPositionRiskJob |
| Trigger Close | ClosePositionJob |
| After Liquidation | Disable account, CRITICAL notification, log incident |

---

## Common Patterns

### Checking State

**Step** (Spatie):
- `$step->state->equals(Completed::class)`
- `$step->state->isOneOf(Completed::class, Skipped::class)`

**Order** (String):
- `$order->status === 'FILLED'`
- `in_array($order->status, ['FILLED', 'PARTIALLY_FILLED'])`

**Position** (String):
- `$position->status === 'OPEN'`

---

### Transitioning State

**Step** (Spatie):
- `$step->state->transitionTo(Completed::class)` - Triggers transition class, runs handle(), saves

**Order** (Manual):
- `$order->update(['status' => 'FILLED'])` - Direct update

**Position** (Manual):
- `$position->update(['status' => 'CLOSED', 'closed_at' => now()])`

---

### State Machine Benefits

| Aspect | Step (Complex) | Order/Position (Simple) |
|--------|---------------|------------------------|
| Transitions | Explicit with validation | Direct update |
| Side Effects | Encapsulated in transition classes | Manual |
| Guards | Prevent invalid transitions | None |
| Audit Trail | Automatic (state column) | Manual |
| Type Safety | Yes | String comparison |

---

## Testing

### Unit Tests

**Location**: `tests/Unit/States/`

**Coverage**:
- Valid transitions
- Invalid transitions (expect exception)
- Guard conditions
- Side effects (timestamps, etc.)

### Integration Tests

**Location**: `tests/Integration/States/`

**Coverage**:
- Full job lifecycle
- Retry flow
- Failure flow
- Order status updates from API
- Position closure flow

---

## Future Enhancements

- Account state machine (active, disabled, suspended, liquidated)
- ExchangeSymbol state machine (active, cooling_down, disabled)
- State transition history table (audit log)
- State machine visualization (flowchart generation)
- State-based event listeners
- Automatic retry policies based on state history
