# Application Logs System

## Overview
Comprehensive model change tracking system that automatically logs all attribute changes across all models using Laravel observers. Provides a complete audit trail of data modifications with intelligent false positive prevention and RAW value comparison to prevent type casting issues.

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
- `previous_value` - Old value (TEXT column, stores RAW database value as string)
- `new_value` - New value (TEXT column, stores RAW database value as string)
- `message` - Human-readable description of change
- `created_at` - Timestamp of change

**Important**: `previous_value` and `new_value` are TEXT columns (not JSON) to store RAW database values without type casting. Values are stored as strings (e.g., '0' for integer 0, '1' for integer 1).

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
2. **`saving`** - Caches RAW attribute values BEFORE database write
3. **`saved`** - Compares RAW values BEFORE vs AFTER save to detect changes

**Critical Innovation**: Uses `saving()` and `saved()` events to compare RAW database values (not Eloquent-casted values), preventing false positives from type casting (e.g., integer 0 vs boolean false).

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
    ↓
Clear cache to prevent saved() from running

Model->attribute = newValue
Model->save()
    ↓
ApplicationLogObserver::saving()
    ↓
Cache RAW original values using getRawOriginal()
    ↓
Database write happens
    ↓
ApplicationLogObserver::saved()
    ↓
Compare RAW cached values vs RAW new values (both integers/strings)
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
        'new_value' => $value, // RAW value (e.g., 0, not false)
        'message' => "Attribute \"{$attribute}\" created with value: ".$this->formatValue($value),
    ]);
}

// Clear cache to prevent saved() from also running during creation
unset(self::$attributesCache[spl_object_id($model)]);
```

#### `saving(BaseModel $model): void`
Caches RAW attribute values BEFORE database write.

**Critical for accurate comparison**: Stores the ORIGINAL values from the database (before any changes) so they can be compared against the NEW values after save.

```php
// Cache the ORIGINAL RAW attributes from the database (before any changes)
// We need to manually get raw values without casts for accurate comparison
$original = [];
foreach ($model->getOriginal() as $key => $value) {
    // getRawOriginal() returns the actual database value without casting
    $original[$key] = $model->getRawOriginal($key);
}

self::$attributesCache[spl_object_id($model)] = $original;
```

**Why this works**:
- Before user calls `save()`, model has OLD values in database
- User sets new values: `$model->is_eligible = true;`
- `saving()` caches the RAW original values (e.g., 0 for boolean false)
- Database write happens
- `saved()` compares RAW cached (0) vs RAW new (1)
- Both are integers, so comparison is accurate (0 vs 1, not 0 vs false)

#### `saved(BaseModel $model): void`
Compares RAW values before and after save.

**Prevents false positives**: Compares integer vs integer (0 vs 0), not integer vs boolean (0 vs false).

```php
$objectId = spl_object_id($model);

// No cached before state? Skip (happens when model was just created)
if (!isset(self::$attributesCache[$objectId])) {
    return;
}

// Get RAW attributes AFTER save (no casts applied)
$rawAfterSave = $model->getAttributes();
$rawBeforeSave = self::$attributesCache[$objectId];

// Compare each attribute for changes (RAW vs RAW)
foreach ($rawAfterSave as $attribute => $newRawValue) {
    $oldRawValue = $rawBeforeSave[$attribute] ?? null;

    // No change? Skip
    if ($oldRawValue === $newRawValue) {
        continue;
    }

    if ($this->shouldSkipLogging($model, $attribute, $oldRawValue, $newRawValue)) {
        continue;
    }

    ApplicationLog::create([
        'loggable_type' => get_class($model),
        'loggable_id' => $model->getKey(),
        'event_type' => 'attribute_changed',
        'attribute_name' => $attribute,
        'previous_value' => $oldRawValue,  // RAW database value (e.g., 0, not false)
        'new_value' => $newRawValue,       // RAW database value (e.g., 1, not true)
        'message' => $this->buildChangeMessage($attribute, $oldRawValue, $newRawValue),
    ]);
}

// Clean up cached attributes
unset(self::$attributesCache[$objectId]);
```

**Real-World Example**:
```php
// ExchangeSymbol has boolean cast: 'is_eligible' => 'boolean'
$symbol = ExchangeSymbol::find(1);
// Database has: is_eligible = 0 (tinyint)
// Eloquent returns: $symbol->is_eligible = false (boolean)

$symbol->is_eligible = false; // User sets to false (same value)
$symbol->save();

// ❌ OLD APPROACH (using updated() event):
// - Compares getRawOriginal('is_eligible') = 0 (integer)
// - Against getAttributes()['is_eligible'] = false (boolean after casting)
// - 0 !== false, so creates FALSE POSITIVE log!

// ✅ NEW APPROACH (using saving() and saved() events):
// - saving(): Caches getRawOriginal('is_eligible') = 0 (integer)
// - Database write: is_eligible stays 0
// - saved(): Compares cached 0 vs getAttributes()['is_eligible'] = 0 (integer)
// - 0 === 0, so NO LOG CREATED! ✅
```

#### `shouldSkipLogging(BaseModel $model, string $attribute, mixed $oldValue, mixed $newValue): bool`
Four-level filtering system to prevent false positive logs:

**Level 0: Global Blacklist** (applies to ALL models)
```php
protected const GLOBAL_BLACKLIST = [
    'updated_at',
    'created_at',
    'deleted_at',
    'remember_token',
];

if (in_array($attribute, self::GLOBAL_BLACKLIST)) {
    return true; // Skip it
}
```

These columns are **automatically excluded for all models** and never logged. Add more columns here if needed globally.

**Level 1: Per-Model Static Blacklist**
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
    public array $skipsLogging = ['last_seen_at', 'cached_balance'];
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
- Carbon timestamps with same time

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
- JSON encoding (e.g., key order: `{"a":1,"b":2}` vs `{"b":2,"a":1}`)
- Numeric precision (e.g., `"5.00000000"` vs `5`)
- Null handling (e.g., `null` vs empty string)
- Carbon timestamps (same time, different instance)

**NOTE**: Boolean casting (0 vs false) is now handled by comparing RAW values in the observer, so ValueNormalizer doesn't need boolean-specific logic.

**Main Method**: `areEqual(mixed $a, mixed $b): bool`

**Normalization Logic**:

1. **Exact Match** (fastest path):
```php
if ($oldValue === $newValue) {
    return true;
}
```

2. **Null Handling**:
```php
if ($a === null && $b === null) return true;
if ($a === null || $b === null) return false;
```

3. **Numeric Comparison** (integers, floats, numeric strings):
```php
if (is_numeric($a) && is_numeric($b)) {
    return (float)$a === (float)$b;
}
// Examples:
// "5.00000000" === 5 → true
// "10" === 10 → true
// 1.0 === 1 → true
```

4. **JSON Comparison** (arrays, objects, JSON strings):
```php
if (self::isJsonLike($oldValue) && self::isJsonLike($newValue)) {
    return self::normalizeJson($oldValue) === self::normalizeJson($newValue);
}
// Examples:
// '{"a":1,"b":2}' === '{"b":2,"a":1}' → true (after normalization)
// [1,2,3] === [1,2,3] → true
```

5. **Carbon Comparison**:
```php
if ($oldValue instanceof Carbon && $newValue instanceof Carbon) {
    return $oldValue->equalTo($newValue);
}
```

6. **String Comparison** (fallback):
```php
return (string)$a === (string)$b;
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

## BaseModel Integration

### LogsApplicationEvents Trait
**Location**: `packages/martingalian/core/src/Concerns/BaseModel/LogsApplicationEvents.php`

**Purpose**: Provides automatic observer registration and unified logging interface for all models

**Automatic Registration**:
The trait automatically registers `ApplicationLogObserver` when the model boots:
```php
protected static function bootLogsApplicationEvents(): void
{
    static::observe(ApplicationLogObserver::class);
}
```

**Usage**:
```php
use Martingalian\Core\Abstracts\BaseModel;

class Step extends BaseModel
{
    // LogsApplicationEvents trait is already included in BaseModel
    // ApplicationLogObserver is automatically registered

    // Optional: Skip specific attributes
    public array $skipsLogging = [
        'last_seen_at',
        'cached_balance',
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

**Manual Logging** (for custom events):
```php
$model->appLog(
    eventType: 'job_failed',
    metadata: ['error' => 'Connection timeout'],
    relatable: $apiSystem,
    message: 'Failed to sync prices'
);
```

## Model Observers

### Best Practices
Model observers should **ONLY** contain model-specific business logic. Application logging is handled automatically by ApplicationLogObserver.

**❌ DEPRECATED** (no longer needed):
```php
use Martingalian\Core\Concerns\LogsModelChanges;

class OrderObserver
{
    use LogsModelChanges; // ❌ Remove this

    public function created(Order $model): void
    {
        $this->logModelCreation($model); // ❌ Remove this - automatic now
    }

    public function updated(Order $model): void
    {
        $this->logModelUpdate($model); // ❌ Remove this - automatic now
    }
}
```

**✅ CORRECT** (business logic only):
```php
class OrderObserver
{
    public function creating(Order $model): void
    {
        // Business logic: generate UUIDs
        $model->uuid ??= Str::uuid()->toString();
    }

    public function updating(Order $model): void
    {
        // Business logic: set filled timestamp
        if ($model->status === 'FILLED') {
            $model->filled_at = now();
        }
    }
}
```

**Examples of clean observers**:
- **OrderObserver** - UUID generation, order threshold validation
- **PositionObserver** - UUID generation
- **AccountObserver** - UUID generation
- **ExchangeSymbolObserver** - Delisting notification
- **IndicatorObserver** - Placeholder (no business logic yet)
- **ApiSystemObserver** - Placeholder (no business logic yet)

## Database Schema

### application_logs
```sql
CREATE TABLE application_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loggable_type VARCHAR(255) NOT NULL,
    loggable_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    attribute_name VARCHAR(255) NOT NULL,
    previous_value TEXT NULL,  -- Changed from JSON to TEXT
    new_value TEXT NULL,        -- Changed from JSON to TEXT
    message TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_loggable (loggable_type, loggable_id),
    INDEX idx_created_at (created_at),
    INDEX idx_attribute_name (attribute_name)
);
```

**Migration**: `2025_11_24_003611_change_application_logs_value_columns_to_text.php`

Changed `previous_value` and `new_value` from JSON to TEXT to store RAW scalar values without JSON encoding. Values are stored as strings (e.g., '0', '1', '3.14', 'LONG').

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
    // Static blacklist - never log these attributes
    public array $skipsLogging = [
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
- Stores actual database values in TEXT columns, not PHP representations
- Prevents type juggling during comparison
- Compares integer vs integer (0 vs 0), not integer vs boolean (0 vs false)

### Optimization 2: Early Returns
- Static blacklist checked first (fastest)
- Strict equality checked second (fast)
- Semantic equality checked third (moderate)
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

### Optimization 5: Cached Attributes
- Uses static array keyed by `spl_object_id()` to cache attributes
- Avoids polluting model's own attributes
- Automatically cleaned up after `saved()` event

## Testing

### Feature Tests
**Location**: `tests/Feature/ApplicationLogObserverTest.php`

**Key Tests**:
✅ Logs all initial attribute values when model is created
✅ Does not create false positive log when boolean value doesn't change (0 vs 0)
✅ Creates proper log when boolean value actually changes (0 vs 1)
✅ Stores RAW database values in logs (not casted booleans)
✅ Does not log globally blacklisted attributes (updated_at, created_at, etc.)
✅ Correctly handles multiple consecutive updates without false positives

**Example Test**:
```php
test('does not create false positive log when boolean value does not actually change', function () {
    // Create a new ExchangeSymbol with is_eligible = false (stored as 0 in DB)
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'is_eligible' => false,
    ]);

    // Get count of logs before the update
    $logCountBefore = ApplicationLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'is_eligible')
        ->count();

    // Save the model again without changing is_eligible
    $exchangeSymbol->auto_disabled = true; // Change a different field
    $exchangeSymbol->save();

    // Get count of logs after the update
    $logCountAfter = ApplicationLog::where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('event_type', 'attribute_changed')
        ->where('attribute_name', 'is_eligible')
        ->count();

    // Should NOT have created a new log for is_eligible (still 0 in database)
    expect($logCountAfter)->toBe($logCountBefore);
});
```

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
// 2025-11-24 00:45:12: Attribute "state" changed from "Running" to "Completed"
// 2025-11-24 00:44:58: Attribute "state" changed from "Dispatched" to "Running"
// 2025-11-24 00:44:45: Attribute "state" changed from "Pending" to "Dispatched"
```

### Exclude Sensitive Attributes
```php
class User extends BaseModel
{
    public array $skipsLogging = [
        'password',
        'remember_token',
        'api_key',
    ];
}
```

## Important Notes

⚠️ **RAW Values** - Observer uses `saving()` and `saved()` events to compare RAW database values (integers like 0 and 1), not Eloquent-casted values (booleans like false and true).

✅ **TEXT Columns** - `previous_value` and `new_value` are TEXT columns that store RAW values as strings (e.g., '0', '1', '3.14').

✅ **No False Positives** - Comparing RAW vs RAW (0 vs 0) instead of RAW vs CASTED (0 vs false) prevents false positive logs.

✅ **Semantic Equality** - ValueNormalizer prevents false positives from JSON key order and numeric precision differences.

✅ **Four-Level Filtering** - Combine global blacklist, per-model blacklist, semantic equality, and dynamic logic for precise control.

❌ **Don't Log Passwords** - Always blacklist sensitive attributes using `$skipsLogging`.

✅ **Disable for Seeders** - Use `ApplicationLog::disable()` during bulk operations to improve performance.

✅ **Clean Observers** - Model observers should only contain business logic. Remove LogsModelChanges trait and manual logging calls.

## Troubleshooting

### False Positives (0 vs false)
**Problem**: Logs showing change from `0` to `false` (or vice versa) when value didn't actually change.

**Solution**: This is now fixed! The observer uses `saving()` and `saved()` events to compare RAW database values (both 0) instead of mixing RAW and casted values (0 vs false).

**How it works**:
1. `saving()` caches RAW original values using `getRawOriginal()` → 0 (integer)
2. `saved()` compares cached RAW vs new RAW using `getAttributes()` → 0 vs 0 (both integers)
3. No log created because 0 === 0 ✅

### Too Many Logs Created
1. Check if attributes are in `$skipsLogging` array
2. Verify ValueNormalizer is preventing false positives
3. Implement custom `skipLogging()` method for edge cases

### Missing Logs
1. Verify `ApplicationLog::isEnabled()` returns `true`
2. Check if attribute is in `$skipsLogging` blacklist
3. Verify model extends `BaseModel` (which includes `LogsApplicationEvents` trait)
4. Check if `skipLogging()` method is returning `true` unexpectedly
5. Check if observer cache was cleared properly after `created()` event
