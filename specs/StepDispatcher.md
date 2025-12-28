# Step Dispatcher System

## Overview

Step-based job execution system using state machines for reliable, sequential task processing with parent-child dependencies, automatic retry logic, and failure cascading.

---

## Core Concepts

### Step

A database record representing a job to be executed. Contains:
- **Action**: The job class to dispatch
- **Arguments**: Constructor parameters (JSON)
- **State**: Current execution state
- **Parent-Child**: Optional dependency relationship
- **Dispatch Group**: For load balancing across servers

### StepsDispatcher

Manages dispatcher locks and active groups per server. Ensures only one dispatcher tick runs per group at a time.

### StepDispatcher (Support Class)

Core dispatcher logic running every second via scheduled command. Processes steps through a sequential pipeline.

---

## State Machine

### States

| State | Description | Terminal? |
|-------|-------------|-----------|
| `Pending` | Waiting to be dispatched | No |
| `Dispatched` | Job sent to queue (transient) | No |
| `Running` | Job is executing | No |
| `Completed` | Successfully finished | Yes |
| `Failed` | Error occurred, may retry | Yes |
| `Skipped` | Parent was skipped | Yes |
| `Cancelled` | Manually cancelled or parent cancelled | Yes |
| `Stopped` | Stopped by system (no retry) | Yes |
| `NotRunnable` | Missing dependencies | Yes |

### State Transitions

```
Pending → Dispatched → Running → Completed
                              → Failed (→ Pending if retry)
                              → Stopped
        → Cancelled
        → Skipped
        → NotRunnable

Running → Pending (job calls retryJob())
```

---

## Dispatcher Pipeline

The dispatcher runs sequentially, with early return if any step performs work:

| Phase | Action | Purpose |
|-------|--------|---------|
| 0 | Acquire lock | Prevent concurrent dispatcher runs |
| 1 | Circuit breaker check | Skip if dispatching is disabled |
| 2 | Skip children | Mark children as Skipped if parent is Skipped |
| 3 | Cascade cancellations | Mark children as Cancelled if parent is Cancelled |
| 4 | Promote resolve-exception | Handle JustResolveException recovery |
| 5 | Transition parents to Failed | Mark parent Failed if all children failed |
| 6 | Cascade failures | Mark children Failed if parent is Failed |
| 7 | Transition parents to Completed | Mark parent Completed if all children concluded |
| 8 | Retry exhausted steps | Transition to Failed if max retries reached |
| 9 | Pick dispatchable steps | Find Pending steps ready to dispatch |
| 10 | Dispatch steps | Send jobs to queue |

---

## Business Rules

### Parent-Child Dependencies

1. **Parent Completion**: Parent cannot complete until ALL children are in concluded states (Completed, Skipped)
2. **Parent Failure Cascades**: If parent transitions to Failed, ALL children must transition to Failed
3. **Parent Skip Cascades**: If parent is Skipped, ALL children must transition to Skipped
4. **Parent Cancellation Cascades**: If parent is Cancelled, ALL children must transition to Cancelled
5. **Child Failure Propagates Up**: If ALL children are in failed states, parent transitions to Failed

### Dispatch Rules

1. **Timing**: Step only dispatchable if `dispatch_after <= now()`
2. **Group Isolation**: Each server handles specific dispatch groups
3. **Pessimistic Locking**: DB-level locks prevent race conditions
4. **Sequential Execution**: Early return if any phase performs work

### Retry Rules

1. **Retry via State Transition**: Jobs call `retryJob()` to transition Running → Pending
2. **Max Retries**: After max retries, step transitions to Failed
3. **Backoff**: Each retry can set custom `dispatch_after` for backoff

---

## Queue Configuration

### Valid Queues

| Queue | Purpose |
|-------|---------|
| `default` | Standard operations |
| `priority` | High priority (auto-assigned when step priority='high') |
| `candles` | Dedicated for candle data fetching |
| `indicators` | Dedicated for indicator calculations |
| `{hostname}` | Server-specific tasks |

### Queue Assignment

- Steps with `priority='high'` route to 'priority' queue
- Invalid queue names fallback to 'default'
- Queue determined by StepObserver on step creation

---

## Database Schema

### steps table

| Column | Purpose |
|--------|---------|
| `id`, `uuid` | Identifiers |
| `parent_step_id` | FK to parent step (nullable) |
| `relatable_type/id` | Polymorphic - what this step operates on |
| `action` | Job class name |
| `arguments` | JSON constructor args |
| `queue` | Queue name |
| `dispatch_group` | For load balancing |
| `state` | State class name |
| `dispatch_after` | Delay dispatching until |
| `started_at`, `completed_at` | Timestamps |
| `error_message`, `error_stack_trace` | Error details |
| `response` | JSON job result |

### steps_dispatchers table

| Column | Purpose |
|--------|---------|
| `dispatch_group` | Which group this server handles |
| `is_dispatching` | Lock flag |
| `started_at`, `ended_at` | Lock timestamps |

---

## Circuit Breaker

A global kill switch that prevents dispatching new jobs while allowing running jobs to complete.

### Purpose

Enable graceful Horizon restarts and code deployments without orphaning steps.

### Behavior

**When Disabled** (`can_dispatch_steps = false`):
- All state management phases execute normally
- Parents can complete, failures can cascade
- **Only pending step dispatch is blocked**
- Running jobs continue normally

**When Enabled** (default):
- Normal operation
- All phases execute including dispatch

### Safe Restart Detection

Check if safe to restart Horizon:
1. Circuit breaker is DISABLED
2. No steps in Running state
3. No steps in Dispatched state

### Deployment Workflow

1. Disable circuit breaker
2. Wait for active jobs to drain
3. Deploy code changes
4. Restart Horizon
5. Re-enable circuit breaker

---

## Monitoring

### Dashboard

Real-time monitoring at `/step-dispatcher` route with:
- Global metrics by state
- Per-hostname metrics
- Active step classes table
- Circuit breaker toggle
- Auto-refresh every 3 seconds

### Logs

Channel: `dispatcher` at `storage/logs/dispatcher.log`

---

## Performance Optimizations

1. **Early Returns**: Each phase returns if it performs work
2. **Pessimistic Locking**: DB-level locks prevent race conditions
3. **Group Isolation**: Load distribution across servers
4. **Index Coverage**: All queries use indexed columns
5. **Batch Processing**: Multiple steps per tick

---

## Job Implementation

### Lifecycle Hooks

Jobs extending BaseQueueableJob have access to:
- `retryJob(Carbon $dispatchAfter)` - Transition to Pending for retry
- `reportAndFail(Throwable $e)` - Transition to Failed with error
- `handleException(Throwable $e)` - Centralized exception handling

### Exception Types

| Exception | Behavior |
|-----------|----------|
| `JustEndException` | End without fail/complete |
| `JustResolveException` | Mark as resolved without failure |
| `NonNotifiableException` | Suppress notification |
| `MaxRetriesReachedException` | Exhausted retries |
