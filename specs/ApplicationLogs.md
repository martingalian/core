# Application Logs System

## Overview
Comprehensive model change tracking system that automatically logs all attribute changes across all models using Laravel observers. Provides a complete audit trail of data modifications with intelligent false positive prevention.

## Core Components

### ApplicationLog Model
**Location**: `packages/martingalian/core/src/Models/ApplicationLog.php`

**Purpose**: Stores audit trail of all model attribute changes

**Key Fields**:
- `id` - Primary key
- `loggable_type` - Model class name (polymorphic)
- `loggable_id` - Model ID (polymorphic)
- `event_type` - Type of event: `attribute_created`, `attribute_changed`
- `attribute_name` - Name of the changed attribute
- `previous_value` - Old value (raw database value, not cast)
- `new_value` - New value (raw database value, not cast)
- `message` - Human-readable description of change
- `created_at` - Timestamp of change

**Static Methods**:
```php
// Enable/disable logging globally
ApplicationLog::enable();
ApplicationLog::disable();

// Check if logging is enabled
ApplicationLog::isEnabled(); // Returns bool
```

**Indexes**:
- `loggable_type`, `loggable_id` - for retrieving all changes for a model
- `created_at` - for time-based queries
- `attribute_name` - for filtering by specific attributes

### ApplicationLogObserver
**Location**: `packages/martingalian/core/src/Observers/ApplicationLogObserver.php`

**Purpose**: Observes all model events and creates ApplicationLog entries

**Events Handled**:
1. **`created`** - Logs all initial attribute values
2. **`updated`** - Logs only changed attributes

**Flow**:
```php
Model::create([...])
    ↓
ApplicationLogObserver::created()
    ↓
For each attribute in model->getAttributes()
    ↓
Check shouldSkipLogging()
    ↓
Create ApplicationLog with event_type='attribute_created'

Model::update([...])
    ↓
ApplicationLogObserver::updated()
    ↓
For each changed attribute in model->getChanges()
    ↓
Check shouldSkipLogging()
    ↓
Create ApplicationLog with event_type='attribute_changed'
```

**Key Methods**:

#### `created(BaseModel $model): void`
Logs all initial attribute values when a model is created.

**Uses RAW values** from `getAttributes()` (not cast) to ensure accurate database representation.

```php
foreach ($model->getAttributes() as $attribute => $value) {
    if ($this->shouldSkipLogging($model, $attribute, null, $value)) {
        continue;
    }

    ApplicationLog::create([
        'loggable_type' => get_class($model),
        'loggable_id' => $model->getKey(),
        'event_type' => 'attribute_created',
        'attribute_name' => $attribute,
        'previous_value' => null,
        'new_value' => $value, // RAW value
        'message' => "Attribute \"{$attribute}\" created with value: ".$this->formatValue($value),
    ]);
}
```

#### `updated(BaseModel $model): void`
Logs only changed attributes when a model is updated.

**Uses RAW values** from `getRawOriginal()` and `getAttributes()` (not cast) for accurate comparison.

```php
foreach ($model->getChanges() as $attribute => $newValue) {
    $oldValueRaw = $model->getRawOriginal($attribute);
    $newValueRaw = $model->getAttributes()[$attribute] ?? null;

    if ($this->shouldSkipLogging($model, $attribute, $oldValueRaw, $newValueRaw)) {
        continue;
    }

    ApplicationLog::create([
        'loggable_type' => get_class($model),
        'loggable_id' => $model->getKey(),
        'event_type' => 'attribute_changed',
        'attribute_name' => $attribute,
        'previous_value' => $oldValueRaw, // RAW value
        'new_value' => $newValueRaw,      // RAW value
        'message' => $this->buildChangeMessage($attribute, $oldValueRaw, $newValueRaw),
    ]);
}
```

#### `shouldSkipLogging(BaseModel $model, string $attribute, mixed $oldValue, mixed $newValue): bool`
Three-level filtering system to prevent false positive logs:

**Level 1: Static Blacklist**
```php
$skipsLogging = $model->skipsLogging ?? [];
if (in_array($attribute, $skipsLogging)) {
    return true; // Skip it
}
```

Models can define a `$skipsLogging` property to exclude specific attributes:
```php
class MyModel extends BaseModel
{
    public array $skipsLogging = ['updated_at', 'last_seen_at'];
}
```

**Level 2: Semantic Equality Check** (ValueNormalizer)
```php
if (ValueNormalizer::areEqual($oldValue, $newValue)) {
    return true; // Values are semantically equal - skip logging
}
```

Prevents false positives like:
- `"5.00000000"` vs `5` (numeric strings vs integers)
- `{"a":1,"b":2}` vs `{"b":2,"a":1}` (JSON key order differences)
- `1` vs `true` (boolean/integer coercion)

**Level 3: Dynamic skipLogging() Method**
```php
if (method_exists($model, 'skipLogging')) {
    if ($model->skipLogging($attribute, $oldValue, $newValue) === true) {
        return true; // Skip it
    }
}
```

Models can implement custom logic:
```php
class MyModel extends BaseModel
{
    public function skipLogging(string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        // Custom logic: Don't log changes to balance if < $0.01 difference
        if ($attribute === 'balance') {
            return abs((float)$oldValue - (float)$newValue) < 0.01;
        }

        return false;
    }
}
```

**Returns**: `true` to skip logging, `false` to log the change

### ValueNormalizer
**Location**: `packages/martingalian/core/src/Support/ValueNormalizer.php`

**Purpose**: Semantic value comparison to prevent false positive application logs

**Problem Solved**: Laravel's Eloquent can save values in different formats than retrieved due to:
- Type casting (e.g., `bool` → `0/1` in database)
- JSON encoding (e.g., key order: `{"a":1,"b":2}` vs `{"b":2,"a":1}`)
- Numeric precision (e.g., `"5.00000000"` vs `5`)
- Null handling (e.g., `null` vs empty string)

Without ValueNormalizer, these would create false positive logs showing "changes" when values are semantically identical.

**Main Method**: `areEqual(mixed $a, mixed $b): bool`

**Normalization Logic**:

1. **Null Handling**:
```php
if ($a === null && $b === null) return true;
if ($a === null || $b === null) return false;
```

2. **Numeric Comparison** (integers, floats, numeric strings):
```php
if (is_numeric($a) && is_numeric($b)) {
    return (float)$a === (float)$b;
}
// Examples:
// "5.00000000" === 5 → true
// "10" === 10 → true
// 1.0 === 1 → true
```

3. **JSON Comparison** (arrays, objects, JSON strings):
```php
$jsonA = self::tryDecodeJson($a);
$jsonB = self::tryDecodeJson($b);

if ($jsonA !== null && $jsonB !== null) {
    return $jsonA === $jsonB; // PHP's === handles array comparison
}
// Examples:
// '{"a":1,"b":2}' === '{"b":2,"a":1}' → true (after decode)
// [1,2,3] === [1,2,3] → true
```

4. **String Comparison** (fallback):
```php
return (string)$a === (string)$b;
```

**Helper Method**: `tryDecodeJson(mixed $value): ?array`
```php
// Returns decoded array if valid JSON, null otherwise
$value = '{"a":1,"b":2}';
$result = ValueNormalizer::tryDecodeJson($value);
// Returns: ['a' => 1, 'b' => 2]

$value = "not json";
$result = ValueNormalizer::tryDecodeJson($value);
// Returns: null
```

**Real-World Example**:

**Before ValueNormalizer** (false positive):
```php
// Step model has JSON `arguments` column
$step = Step::find(1);
$step->arguments = ['symbol' => 'BTCUSDT', 'exchange' => 'binance'];
$step->save();

// Later, exact same data is saved again
$step->arguments = ['exchange' => 'binance', 'symbol' => 'BTCUSDT']; // Different key order!
$step->save();

// ❌ Creates ApplicationLog showing "change" from:
// previous_value: '{"symbol":"BTCUSDT","exchange":"binance"}'
// new_value:      '{"exchange":"binance","symbol":"BTCUSDT"}'
```

**After ValueNormalizer** (prevented):
```php
// Same scenario
$step->arguments = ['symbol' => 'BTCUSDT', 'exchange' => 'binance'];
$step->save();

$step->arguments = ['exchange' => 'binance', 'symbol' => 'BTCUSDT'];
$step->save();

// ✅ No ApplicationLog created - ValueNormalizer recognizes semantic equality
// Both JSON strings decode to same array structure
```

**Usage**:
```php
use Martingalian\Core\Support\ValueNormalizer;

// Check if two values are semantically equal
if (ValueNormalizer::areEqual($oldValue, $newValue)) {
    // Values are the same, skip processing
}

// Examples:
ValueNormalizer::areEqual("5.00000000", 5); // true
ValueNormalizer::areEqual('{"a":1,"b":2}', '{"b":2,"a":1}'); // true
ValueNormalizer::areEqual(1, true); // false (not both numeric)
ValueNormalizer::areEqual(null, null); // true
ValueNormalizer::areEqual("hello", "hello"); // true
```

## BaseModel Integration

### LogsApplicationEvents Trait
**Location**: `packages/martingalian/core/src/Traits/LogsApplicationEvents.php`

**Purpose**: Provides unified logging interface for all models

**Usage**:
```php
use Martingalian\Core\Abstracts\BaseModel;

class Step extends BaseModel
{
    use LogsApplicationEvents;

    // Optional: Skip specific attributes
    public array $skipsLogging = [
        'updated_at',
    ];

    // Optional: Custom skip logic
    public function skipLogging(string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        // Don't log trivial balance changes
        if ($attribute === 'balance' && abs((float)$oldValue - (float)$newValue) < 0.01) {
            return true;
        }

        return false;
    }
}
```

**Automatic Registration**:
The trait automatically registers `ApplicationLogObserver` when the model boots:
```php
protected static function bootLogsApplicationEvents(): void
{
    static::observe(ApplicationLogObserver::class);
}
```

## Database Schema

### application_logs
```sql
CREATE TABLE application_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loggable_type VARCHAR(255) NOT NULL,
    loggable_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    attribute_name VARCHAR(255) NOT NULL,
    previous_value TEXT NULL,
    new_value TEXT NULL,
    message TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_loggable (loggable_type, loggable_id),
    INDEX idx_created_at (created_at),
    INDEX idx_attribute_name (attribute_name)
);
```

## Configuration

### Global Enable/Disable
```php
// In AppServiceProvider or config file
use Martingalian\Core\Models\ApplicationLog;

// Disable logging globally (e.g., for seeders)
ApplicationLog::disable();

// Run operations without logging
DB::transaction(function () {
    // Bulk operations
});

// Re-enable logging
ApplicationLog::enable();
```

### Per-Model Configuration
```php
class MyModel extends BaseModel
{
    use LogsApplicationEvents;

    // Static blacklist - never log these attributes
    public array $skipsLogging = [
        'updated_at',
        'last_seen_at',
        'remember_token',
    ];

    // Dynamic skip logic
    public function skipLogging(string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        // Custom conditions
        return false;
    }
}
```

## Querying Logs

### Get All Changes for a Model
```php
$step = Step::find(1);
$logs = ApplicationLog::where('loggable_type', Step::class)
    ->where('loggable_id', $step->id)
    ->orderBy('created_at', 'desc')
    ->get();
```

### Get Changes for Specific Attribute
```php
$logs = ApplicationLog::where('loggable_type', Step::class)
    ->where('loggable_id', $step->id)
    ->where('attribute_name', 'state')
    ->get();
```

### Get Recent Changes Across All Models
```php
$recentChanges = ApplicationLog::orderBy('created_at', 'desc')
    ->limit(100)
    ->get();
```

### Get Changes in Date Range
```php
$logs = ApplicationLog::whereBetween('created_at', [
    now()->subDays(7),
    now(),
])->get();
```

## Performance Considerations

### Optimization 1: Raw Values
- Observer uses `getAttributes()` and `getRawOriginal()` to avoid Eloquent casting overhead
- Stores actual database values, not PHP representations
- Prevents type juggling during comparison

### Optimization 2: Early Returns
- Static blacklist checked first (fastest)
- Semantic equality checked second (fast)
- Dynamic method checked last (slowest)
- Most logs skip at level 1 or 2

### Optimization 3: Bulk Disable
```php
// Disable for seeders/migrations
ApplicationLog::disable();
DB::transaction(function () {
    // Bulk insert 10,000 records
});
ApplicationLog::enable();
```

### Optimization 4: Indexed Queries
- All common query patterns use indexed columns
- Fast lookups by model, attribute, or time range

## Testing

### Unit Tests
**Location**: `tests/Unit/ApplicationLog/`

**Key Tests**:
- ValueNormalizer equality checks (numeric, JSON, string)
- Observer create/update event handling
- Static blacklist filtering
- Dynamic skipLogging() method
- Global enable/disable toggle

### Integration Tests
**Location**: `tests/Integration/ApplicationLog/`

**Key Tests**:
- Full model lifecycle logging
- False positive prevention (JSON key order, numeric precision)
- Bulk operations with logging disabled
- Polymorphic relationship integrity

## Common Patterns

### Temporarily Disable Logging
```php
ApplicationLog::disable();

try {
    // Operations without logging
    Model::insert([...]); // No logs created
} finally {
    ApplicationLog::enable();
}
```

### Audit Trail for User Actions
```php
$step = Step::find(1);
$history = ApplicationLog::where('loggable_type', Step::class)
    ->where('loggable_id', $step->id)
    ->where('attribute_name', 'state')
    ->orderBy('created_at', 'desc')
    ->get();

foreach ($history as $log) {
    echo "{$log->created_at}: {$log->message}\n";
}
// Output:
// 2025-11-23 20:45:12: Attribute "state" changed from "Running" to "Completed"
// 2025-11-23 20:44:58: Attribute "state" changed from "Dispatched" to "Running"
// 2025-11-23 20:44:45: Attribute "state" changed from "Pending" to "Dispatched"
```

### Exclude Sensitive Attributes
```php
class User extends BaseModel
{
    use LogsApplicationEvents;

    public array $skipsLogging = [
        'password',
        'remember_token',
        'api_key',
    ];
}
```

## Important Notes

⚠️ **Use RAW values** - Observer always uses `getAttributes()` and `getRawOriginal()` to store actual database values, not Eloquent-cast values.

✅ **Semantic equality** - ValueNormalizer prevents false positives from type coercion, JSON key order, and numeric precision differences.

✅ **Three-level filtering** - Combine static blacklist, semantic equality, and dynamic logic for precise control.

❌ **Don't log passwords** - Always blacklist sensitive attributes using `$skipsLogging`.

✅ **Disable for seeders** - Use `ApplicationLog::disable()` during bulk operations to improve performance.

## Troubleshooting

### Too Many Logs Created
1. Check if attributes are in `$skipsLogging` array
2. Verify ValueNormalizer is preventing false positives
3. Implement custom `skipLogging()` method for edge cases

### False Positives Still Occurring
1. Check if values are truly identical (use `dd()` to inspect raw values)
2. Verify ValueNormalizer logic covers the specific case
3. Add custom comparison logic to model's `skipLogging()` method

### Missing Logs
1. Verify `ApplicationLog::isEnabled()` returns `true`
2. Check if attribute is in `$skipsLogging` blacklist
3. Verify model uses `LogsApplicationEvents` trait
4. Check if `skipLogging()` method is returning `true` unexpectedly
