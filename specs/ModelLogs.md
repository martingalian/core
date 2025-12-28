# Model Logs System

## Overview

Comprehensive model change tracking system that automatically logs all attribute changes across all models using Laravel observers. Provides a complete audit trail of data modifications with intelligent false positive prevention and RAW value comparison.

---

## Core Components

### ModelLog Model

**Purpose**: Stores audit trail of all model attribute changes

**Key Fields**:
| Field | Type | Description |
|-------|------|-------------|
| `loggable_type` | varchar | Model class name (polymorphic) |
| `loggable_id` | bigint | Model ID (polymorphic) |
| `event_type` | varchar | `attribute_created` or `attribute_changed` |
| `attribute_name` | varchar | Name of the changed attribute |
| `previous_value` | LONGTEXT | Old value (RAW database value) |
| `new_value` | LONGTEXT | New value (RAW database value) |
| `message` | text | Human-readable description |
| `created_at` | timestamp | When change occurred |

**Important**: Values stored as LONGTEXT (not JSON) to preserve RAW database values without type casting.

**Indexes**:
- `loggable_type, loggable_id` - Model lookups
- `created_at` - Time-based queries
- `attribute_name` - Attribute filtering

---

## How It Works

### Observer Flow

```
Model::create([...])
    ↓
ModelLogObserver::created()
    ↓
Log all initial attribute values

Model->attribute = newValue
Model->save()
    ↓
ModelLogObserver::saving()
    ↓
Cache RAW original values using getRawOriginal()
    ↓
Database write happens
    ↓
ModelLogObserver::saved()
    ↓
Compare RAW cached vs RAW new values
    ↓
Create ModelLog if actually changed
```

### Key Innovation: RAW Value Comparison

**Problem**: Eloquent casts can cause false positives (0 vs false comparison)

**Solution**: Compare RAW database values in both `saving()` and `saved()` events

| Event | Action |
|-------|--------|
| `saving()` | Cache RAW original values via `getRawOriginal()` |
| `saved()` | Compare cached RAW vs new RAW (both integers) |

**Example**:
- Database has: `has_taapi_data = 0` (tinyint)
- User sets: `$model->has_taapi_data = false`
- OLD approach: 0 vs false = different (FALSE POSITIVE)
- NEW approach: 0 vs 0 = same (NO LOG)

---

## Skip Logging Filters

Four-level filtering system prevents false positive logs:

### Level 0: Global Blacklist (All Models)

| Attribute | Reason |
|-----------|--------|
| `updated_at` | Always changes on save |
| `created_at` | Only set once |
| `deleted_at` | Soft delete tracking |
| `remember_token` | Session data |

### Level 1: Per-Model Static Blacklist

Models can define `$skipsLogging` property:
- Exclude specific attributes permanently
- No runtime logic needed

### Level 2: Semantic Equality (ValueNormalizer)

Prevents false positives from:
| Type | Example |
|------|---------|
| Numeric strings | "5.00000000" vs 5 |
| JSON key order | `{"a":1,"b":2}` vs `{"b":2,"a":1}` |
| Carbon timestamps | Same time, different instance |

### Level 3: Dynamic skipLogging() Method

Models can implement custom logic:
- Skip balance changes < $0.01
- Skip non-significant changes
- Runtime conditions

---

## ValueNormalizer

**Purpose**: Semantic value comparison to prevent false positives

### Normalization Logic

| Priority | Check | Action |
|----------|-------|--------|
| 1 | Exact match | Return true |
| 2 | Both null | Return true |
| 3 | One null | Return false |
| 4 | Both numeric | Compare as float |
| 5 | Both JSON-like | Normalize and compare |
| 6 | Both Carbon | Use equalTo() |
| 7 | Fallback | Compare as strings |

---

## BaseModel Integration

### LogsApplicationEvents Trait

**Automatic Registration**: Trait registers `ModelLogObserver` on model boot

**All BaseModel descendants automatically get**:
- Attribute change logging
- Creation logging
- Skip logic support

### Manual Logging (Custom Events)

For custom events beyond attribute changes:
- `$model->appLog()` method available
- Specify event type, metadata, related model, message

---

## Observer Best Practices

### DO

| Practice | Reason |
|----------|--------|
| Business logic only | Observers handle UUID generation, timestamps |
| Let ModelLogObserver handle logging | Automatic, consistent |
| Use `$skipsLogging` for exclusions | Clean, declarative |

### DON'T

| Practice | Reason |
|----------|--------|
| Manual logging in observers | Redundant, error-prone |
| Use LogsModelChanges trait | Deprecated |
| Log passwords/secrets | Security risk |

---

## Global Enable/Disable

| Method | Purpose |
|--------|---------|
| `ModelLog::disable()` | Turn off logging (for seeders/migrations) |
| `ModelLog::enable()` | Turn on logging |
| `ModelLog::isEnabled()` | Check status |

**Use Case**: Disable during bulk operations for performance

---

## Querying Logs

### Common Queries

| Purpose | Filter By |
|---------|-----------|
| All changes for a model | `loggable_type` + `loggable_id` |
| Specific attribute changes | `attribute_name` |
| Recent changes | `created_at` ORDER DESC |
| Date range | `whereBetween('created_at', ...)` |

---

## Performance Optimizations

| Optimization | Description |
|--------------|-------------|
| RAW values | Avoids Eloquent casting overhead |
| Early returns | Static blacklist checked first |
| Indexed queries | Fast lookups by model/attribute/time |
| Cached attributes | Uses static array keyed by `spl_object_id()` |
| Bulk disable | Turn off for seeders/migrations |

---

## Database Schema

### model_logs Table

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT | Primary key |
| `loggable_type` | VARCHAR(255) | Model class |
| `loggable_id` | BIGINT | Model ID |
| `event_type` | VARCHAR(50) | created/changed |
| `attribute_name` | VARCHAR(255) | Attribute name |
| `previous_value` | LONGTEXT | Old value (4GB max) |
| `new_value` | LONGTEXT | New value (4GB max) |
| `message` | TEXT | Human-readable |
| `created_at` | TIMESTAMP | When logged |

---

## Common Patterns

### Temporarily Disable Logging

1. Call `ModelLog::disable()`
2. Run bulk operations
3. Call `ModelLog::enable()`

### Audit Trail Query

1. Filter by model type and ID
2. Filter by attribute name (e.g., 'state')
3. Order by created_at DESC
4. Display messages chronologically

### Exclude Sensitive Attributes

Add to model's `$skipsLogging` array:
- `password`
- `remember_token`
- `api_key`
- `api_secret`

---

## Troubleshooting

### Too Many Logs Created

1. Check `$skipsLogging` array
2. Verify ValueNormalizer working
3. Implement custom `skipLogging()` method

### Missing Logs

1. Verify `ModelLog::isEnabled()` is true
2. Check attribute not in blacklist
3. Verify model extends BaseModel
4. Check `skipLogging()` not returning true

### False Positives (0 vs false)

**Now Fixed**: Observer uses `saving()` and `saved()` events to compare RAW values (0 vs 0) instead of mixed types (0 vs false).

---

## Related Systems

- **BaseModel**: Provides LogsApplicationEvents trait
- **Observers**: Handle business logic only
- **ValueNormalizer**: Semantic equality comparisons
