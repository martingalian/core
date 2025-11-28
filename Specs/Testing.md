# Testing Strategy

## Overview
Comprehensive testing using **Pest v4** with feature tests, unit tests, integration tests, and browser tests. Current status: **841 tests passing** (Unit/Feature: 824, Integration: 19).

The project enforces quality through multiple layers:
- **Pest Tests**: Unit, Feature, Integration, and Browser tests
- **PHPStan**: Static analysis at level `max`
- **Pint**: Laravel code style enforcement with strict rules
- **Rector**: PHP code modernization and refactoring
- **Prettier**: Frontend code formatting (resources/)

## Test Structure

### Directory Organization

```
tests/
├── Feature/          # Feature tests (majority)
│   ├── Console/
│   │   └── Commands/
│   ├── Notifications/
│   └── Support/
├── Unit/            # Unit tests (isolated logic)
│   ├── StepDispatcher/
│   └── Support/
├── Integration/     # Integration tests (real rendering)
│   └── Mail/
├── Browser/         # Browser tests (Pest v4)
└── Pest.php         # Pest configuration
```

### Test Types

#### 1. Feature Tests
- Test complete features end-to-end
- Use `RefreshDatabase` trait
- Test database interactions
- Test HTTP endpoints
- Test command execution

Example:
```php
it('stores account balances successfully', function () {
    $user = User::factory()->create();
    $account = ExchangeAccount::factory()->create(['user_id' => $user->id]);

    $this->artisan('cronjobs:store-accounts-balances')
        ->assertSuccessful();

    expect(AccountBalance::count())->toBe(1);
});
```

#### 2. Unit Tests
- Test isolated logic
- No database interactions (usually)
- Mock dependencies
- Test pure functions and calculations

Example:
```php
it('calculates position size correctly', function () {
    $calculator = new PositionSizeCalculator();

    $size = $calculator->calculate(
        accountBalance: 10000,
        riskPercentage: 2,
        entryPrice: 50000,
        stopLoss: 49000
    );

    expect($size)->toBe(0.02);
});
```

#### 3. Integration Tests
- Test real system interactions
- Uses **log mail driver** to validate real email rendering
- Catches bugs that `Mail::fake()` misses
- Tests complete flow (notification → email → HTML)

Base class: `tests/Integration/IntegrationTestCase.php`

Features:
- Overrides phpunit.xml settings
- Enables notifications
- Fakes Pushover HTTP calls
- Provides email parsing helpers

Example:
```php
it('renders alert email with all components', function () {
    $user = User::factory()->create([
        'name' => 'John Smith',
        'notification_channels' => ['mail'],
    ]);

    NotificationService::sendToUser(
        user: $user,
        message: 'Test message',
        title: 'Test Alert',
        severity: NotificationSeverity::High
    );

    $this->assertEmailWasSent();
    $this->assertLastEmailContains('Hello John Smith');
    $this->assertLastEmailContains('Test message');
});
```

#### 4. Browser Tests (Pest v4)
- Test real browser interactions
- Use Chrome, Firefox, Safari
- Test JavaScript functionality
- Take screenshots
- Test responsive design

Example:
```php
it('submits login form successfully', function () {
    $user = User::factory()->create();

    $page = visit('/login');

    $page->assertSee('Sign In')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('Sign In')
        ->assertUrl('/dashboard')
        ->assertNoJavascriptErrors();
});
```

## Test Configuration

### phpunit.xml

```xml
<phpunit>
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
    </php>
</phpunit>
```

### Pest.php

```php
// Feature & Browser tests with database
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->freezeTime();

        // Ensure Martingalian record exists
        \Martingalian\Core\Models\Martingalian::firstOrCreate(['id' => 1], [...]);
        Once::flush();
    })
    ->in('Browser', 'Feature');

// Pure unit tests without database
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->freezeTime();
    })
    ->in('Unit');

// Integration tests with database
pest()->extend(Tests\Integration\IntegrationTestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->freezeTime();

        \Martingalian\Core\Models\Martingalian::firstOrCreate(['id' => 1], [...]);
        Once::flush();
    })
    ->in('Integration');
```

### phpstan.neon (Static Analysis)

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
    - phar://phpstan.phar/conf/bleedingEdge.neon

parameters:
    paths:
        - app
        - bootstrap/app.php
        - config
        - database
        - public
        - routes

    level: max
    tmpDir: /tmp/phpstan
```

### pint.json (Code Style)

```json
{
    "preset": "laravel",
    "notPath": ["tests/TestCase.php", "tmp"],
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "strict_comparison": true,
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": true,
            "import_functions": true
        },
        "ordered_class_elements": {...},
        "protected_to_private": true,
        "visibility_required": true
    }
}
```

### package.json (Frontend Linting)

```json
{
    "scripts": {
        "lint": "prettier --write resources/",
        "test:lint": "prettier --check resources/"
    },
    "devDependencies": {
        "prettier": "^3.6.2",
        "prettier-plugin-organize-imports": "^4.3.0",
        "prettier-plugin-tailwindcss": "^0.7.1"
    }
}
```

## Testing Patterns

### Using Factories

Always use model factories for test data:
```php
$user = User::factory()->create([
    'name' => 'Test User',
    'is_active' => true,
]);

$account = ExchangeAccount::factory()
    ->forUser($user)
    ->binance()
    ->create();
```

### Using Datasets

For testing multiple scenarios:
```php
it('validates email format', function (string $email, bool $valid) {
    $validator = new EmailValidator();
    expect($validator->isValid($email))->toBe($valid);
})->with([
    ['valid@example.com', true],
    ['invalid', false],
    ['@example.com', false],
]);
```

### Mocking External Services

```php
use function Pest\Laravel\mock;

it('sends notification via pushover', function () {
    Http::fake([
        'api.pushover.net/*' => Http::response(['status' => 1], 200),
    ]);

    NotificationService::sendToUser(...);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.pushover.net/1/messages.json';
    });
});
```

### Testing Exceptions

```php
it('throws exception for invalid credentials', function () {
    $client = new BinanceApiClient();

    expect(fn() => $client->getAccountInfo())
        ->toThrow(InvalidCredentialsException::class);
});
```

### Testing Notifications

```php
use Illuminate\Support\Facades\Notification;

it('sends notification to admin', function () {
    Notification::fake();

    NotificationService::sendToAdmin(
        message: 'Test',
        title: 'Test'
    );

    // Admin notifications handled via config
    Notification::assertSent(AlertNotification::class);
});
```

### Testing Email Content

```php
use Illuminate\Support\Facades\Mail;

it('includes user name in email', function () {
    Mail::fake();

    $user = User::factory()->create(['name' => 'John']);

    NotificationService::sendToUser($user, 'Test', 'Test');

    Mail::assertSent(AlertMail::class, function ($mail) {
        return $mail->userName === 'John';
    });
});
```

## Integration Test Helpers

### IntegrationTestCase Methods

```php
// Email assertions
$this->assertEmailWasSent();
$this->assertNoEmailWasSent();
$this->assertLastEmailContains('text');
$this->assertLastEmailHasValidHtml();

// Email retrieval
$html = $this->getLastEmailHtml();
$text = $this->getLastEmailText();

// Cleanup
$this->clearEmailLog();
```

### How It Works

1. Tests extend `IntegrationTestCase`
2. `setUp()` configures environment:
   - Enables notifications
   - Sets mail driver to 'log'
   - Fakes Pushover HTTP calls
3. Emails written to `storage/logs/laravel.log`
4. Helpers parse log file to extract HTML/text
5. Tests validate real rendered output

**Key Advantage**: Catches bugs like:
- Variable collisions (`$message` reserved in Laravel Mail)
- Missing recipient specifications
- Missing routing methods
- Template syntax errors
- Blade variable errors

### Integration Test Files

The following integration tests validate real email rendering for critical notification flows:

#### 1. AlertNotificationEmailTest.php (17 tests)
Tests direct `NotificationService::sendToUser()` calls:
- Email structure validation (HTML, DOCTYPE, proper tags)
- Severity badge rendering for all levels (Critical, High, Medium, Info)
- Action buttons and URLs
- User personalization (greetings, names)
- Email footer (hostname, timestamp, support email)
- XSS protection (HTML escaping)
- Multi-line message formatting
- User channel preferences
- Inactive user handling
- NotificationMessageBuilder integration

#### 2. ApiRequestLogObserverNotificationTest.php (9 tests)
Tests API error notifications triggered by `ApiRequestLog` observer:
- 401 IP whitelist errors (user-facing)
- 401 IP whitelist errors (admin-facing for system calls)
- 429 rate limit errors
- 503 maintenance/unavailable errors
- Connection failures
- Bybit-specific error formatting (retCode)
- Email footer with actionable context
- Successful API calls (no notification)

#### 3. BaseQueueableJobExceptionNotificationTest.php (8 tests)
Tests job exception notifications for non-API jobs:
- Formatted admin notification emails
- Stack trace presence
- Hostname inclusion for debugging
- NonNotifiableException handling (no email)
- Complex multi-line exception messages
- Exception details display
- Severity indicators
- XSS protection in exception messages

#### 4. MonitorDataCoherencyNotificationTest.php (8 tests)
Tests stale price monitoring notifications:
- Formatted emails to admin with symbol details
- Oldest/newest update information
- Multiple stale symbols formatting
- Multiple exchanges handling
- Hostname and timestamp
- Price value formatting
- No notification when prices are fresh
- Severity indicators

**Total: Integration tests covering all critical notification paths**

## Running Tests

### Composer Test Commands

The project uses composer scripts for all testing workflows:

#### Full Test Suite
```bash
# Runs ALL tests + static analysis + linting
composer test

# Includes:
# - composer test:type-coverage (Pest type coverage, min 100%)
# - composer test:unit (Unit + Feature tests in parallel)
# - composer test:integration (Integration tests sequentially)
# - composer test:lint (Pint + Rector + Prettier verification)
# - composer test:types (PHPStan static analysis)
```

#### Unit & Feature Tests (Parallel)
```bash
composer test:unit

# Equivalent to: pest --parallel --exclude-testsuite=Integration
# Runs all tests EXCEPT Integration (841 tests)
# Uses parallel execution for speed
```

#### Integration Tests (Sequential)
```bash
composer test:integration

# Equivalent to: pest --testsuite=Integration
# Runs only Integration tests (19 tests)
# Must run sequentially (shares log files)
```

#### Static Analysis & Linting
```bash
# PHPStan static analysis
composer test:types

# Verify code style (no changes)
composer test:lint

# Auto-fix code style issues
composer lint

# Type coverage check (min 100%)
composer test:type-coverage
```

#### Coverage Testing
```bash
composer test:coverage

# Equivalent to: pest --coverage --min=70
# Generates code coverage report
```

### Direct Pest/Artisan Commands

For specific test scenarios, use Pest/Artisan directly:

#### Specific Suite
```bash
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration
```

#### Specific File
```bash
php artisan test tests/Feature/Notifications/AlertNotificationTest.php
```

#### Specific Test
```bash
php artisan test --filter="renders alert email"
php artisan test --filter=testUserCanLogin
```

#### Parallel Execution
```bash
php artisan test --parallel
```

## Test Database

### Configuration

```php
// config/database.php
'testing' => [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'martingalian_test',
    'username' => 'root',
    'password' => 'password',
],
```

### Using RefreshDatabase

```php
uses(RefreshDatabase::class);

it('creates user', function () {
    $user = User::factory()->create();

    expect(User::count())->toBe(1);

    // Database automatically rolled back after test
});
```

### Using DatabaseTransactions

```php
uses(DatabaseTransactions::class);

it('updates user', function () {
    $user = User::factory()->create();
    $user->update(['name' => 'Updated']);

    expect($user->name)->toBe('Updated');

    // Changes rolled back after test
});
```

## Integration Test Best Practices & Common Patterns

### Helper Functions in Pest Tests

**CRITICAL**: Helper functions in Pest tests **CANNOT** access `test()` context. They must create their own dependencies:

```php
// BAD: Will fail with "Undefined property test::$user"
function createTestData(): array
{
    $account = Account::factory()->create([
        'user_id' => test()->user->id,  // ❌ WRONG
    ]);
    return [$account];
}

// GOOD: Helper creates its own user
function createTestData(): array
{
    $user = User::factory()->create();  // ✓ CORRECT

    $account = Account::factory()->create([
        'user_id' => $user->id,  // ✓ CORRECT
    ]);

    return [$account];
}
```

### Quote vs Symbol Models

**IMPORTANT**: `quotes` and `symbols` are **different tables** with different fields:

```php
// quotes table has 'canonical' and 'name' fields
$quote = Quote::firstOrCreate([
    'canonical' => 'usdt',  // Unique identifier
], [
    'name' => 'Tether',     // Display name
]);

// symbols table has 'token', 'name', and 'cmc_id' fields
$symbol = Symbol::firstOrCreate([
    'token' => 'BTC',       // Unique identifier
], [
    'name' => 'Bitcoin',    // Display name
    'cmc_id' => 1,         // CoinMarketCap ID
]);

// ExchangeSymbol links BOTH
$exchangeSymbol = ExchangeSymbol::create([
    'symbol_id' => $symbol->id,  // Base asset (Symbol)
    'quote_id' => $quote->id,    // Quote asset (Quote)
    'api_system_id' => $apiSystem->id,
    // ...
]);
```

**Common Mistake**: Creating Symbol when you need Quote:
```php
// ❌ WRONG: Creates Symbol instead of Quote
$quote = Symbol::create(['canonical' => 'usdt']);

// ✓ CORRECT: Use Quote model for quote assets
$quote = Quote::firstOrCreate(['canonical' => 'usdt'], ['name' => 'Tether']);
```

### Using firstOrCreate() to Avoid Duplicates

**ALWAYS** use `firstOrCreate()` in test helpers to prevent duplicate constraint violations:

```php
// ❌ BAD: Will fail on second test run with duplicate entry error
function createTestData(): array
{
    $apiSystem = ApiSystem::create(['canonical' => 'binance']);
    $quote = Quote::create(['canonical' => 'usdt', 'name' => 'Tether']);
    // ...
}

// ✓ GOOD: Reuses existing or creates new
function createTestData(): array
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        []  // No additional fields needed
    );

    $quote = Quote::firstOrCreate(
        ['canonical' => 'usdt'],
        ['name' => 'Tether']
    );

    $symbol = Symbol::firstOrCreate(
        ['token' => 'BTC'],
        ['name' => 'Bitcoin', 'cmc_id' => 1]
    );
    // ...
}
```

### Required Model Fields

#### Account Model
**CRITICAL**: Account requires `trade_configuration_id`:

```php
// ❌ WRONG: Missing required field
$account = Account::factory()->create([
    'api_system_id' => $apiSystem->id,
    'user_id' => $user->id,
]);

// ✓ CORRECT: Include trade_configuration_id
$account = Account::factory()->create([
    'api_system_id' => $apiSystem->id,
    'user_id' => $user->id,
    'trade_configuration_id' => 1,  // Required!
    'is_active' => true,
    'can_trade' => true,
]);
```

### Decimal Precision in Assertions

**Database stores financial fields with 8 decimal places**:

```php
// ❌ WRONG: Will fail - precision mismatch
expect($position->closing_price)->toBe('51000');

// ✓ CORRECT: Match database precision
expect($position->closing_price)->toBe('51000.00000000');

// Also applies to all financial fields:
// - opening_price, closing_price
// - entry_price, current_price
// - quantity, filled_quantity
// - price, stop_price
// - All decimal(20,8) columns
```

### HTTP Mocking Patterns

#### Integration Test API Mocking Architecture

**CRITICAL**: Integration tests must mock **raw API responses** and let **ApiDataMappers** handle transformations:

```
HTTP Mock (Raw Binance/Bybit Format)
    ↓
ApiDataMapper (Transform & Normalize)
    ↓
Internal Format (Business Logic Uses This)
```

**Example Data Flow:**
```php
// Mock returns RAW format
Http::fake([
    '*//fapi/*/positionRisk*' => Http::response([
        ['symbol' => 'BTCUSDT', 'positionAmt' => '0.1', ...],  // Raw
    ], 200),
]);

// MapsPositionsQuery transforms
// 'BTCUSDT' → 'BTC/USDT'

// Business logic receives internal format
$position->apiClose();  // Uses 'BTC/USDT'
```

#### URL Pattern Requirements

**BaseApiClient** generates URLs with double slashes in testing mode. Http::fake() patterns MUST account for this:

```php
// ❌ WRONG: Single slash pattern won't match
Http::fake([
    '*/fapi/v1/order' => Http::response([...], 200),
]);
// Attempting: https://fapi.binance.com//fapi/v1/order
// Pattern expects: https://fapi.binance.com/fapi/v1/order
// Result: StrayRequestException

// ✓ CORRECT: Double slash pattern
Http::fake([
    '*//fapi/v1/order*' => Http::response([...], 200),
]);
// Matches: https://fapi.binance.com//fapi/v1/order?param=value

// ✓ ALSO CORRECT: Wildcard for version changes
Http::fake([
    '*//fapi/*/positionRisk*' => Http::response([...], 200),
]);
// Matches both v2 and v3: /fapi/v2/positionRisk, /fapi/v3/positionRisk
```

#### HTTP Method Support

BaseApiClient in testing mode supports GET, POST, **PUT**, and DELETE. All throw exceptions for error responses:

```php
// Testing mode implementation (BaseApiClient.php):
if ($method === 'GET') {
    return Http::withHeaders($headers)->get($url, $options['query'] ?? [])->throw()->toPsrResponse();
} elseif ($method === 'POST') {
    $body = $options['json'] ?? $options['query'] ?? [];
    return Http::withHeaders($headers)->post($url, $body)->throw()->toPsrResponse();
} elseif ($method === 'PUT') {  // Required for order modification
    $body = $options['json'] ?? $options['query'] ?? [];
    return Http::withHeaders($headers)->put($url, $body)->throw()->toPsrResponse();
} elseif ($method === 'DELETE') {
    return Http::withHeaders($headers)->delete($url, $options['query'] ?? [])->throw()->toPsrResponse();
}
```

#### Complete Response Fields
Mock responses must include **ALL** fields the application expects:

```php
// ❌ INCOMPLETE: Missing fields will cause errors
Http::fake([
    '*//fapi/v1/order*' => Http::response([
        'orderId' => 123,
        'status' => 'NEW',
    ], 200),
]);

// ✓ COMPLETE: All required fields included
Http::fake([
    '*//fapi/v1/order*' => Http::response([
        'orderId' => 123,
        'symbol' => 'BTCUSDT',        // Raw format, NOT 'BTC/USDT'
        'status' => 'NEW',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'positionSide' => 'LONG',
        'price' => '50000.00',
        'origQty' => '0.1',
        'executedQty' => '0',         // Required by MapsOrderModify
        'avgPrice' => '0',             // Required by MapsOrderModify
        'origType' => 'LIMIT',         // Required by MapsOrderModify
    ], 200),
]);
```

**Required Fields by Endpoint:**

**Order Placement/Query (`/fapi/v1/order`):**
- `orderId`, `symbol`, `status`, `type`, `side`, `positionSide`
- `price`, `origQty`, `executedQty`, `avgPrice`, `origType`

**Position Query (`/fapi/*/positionRisk`):**
- `symbol` (raw format: `'BTCUSDT'`)
- `positionAmt`, `positionSide`, `entryPrice`
- `marginType`, `leverage`, `markPrice`

**User Trades (`/fapi/v1/userTrades`):**
- `symbol`, `id`, `orderId`, `price`, `qty`
- `quoteQty`, `commission`, `commissionAsset`

#### Symbol Format in Mocks

**CRITICAL**: Always use **raw exchange format** in HTTP mocks:

```php
// ✓ CORRECT: Raw Binance format
Http::fake([
    '*//fapi/*/positionRisk*' => Http::response([
        [
            'symbol' => 'BTCUSDT',  // Raw format
            'positionAmt' => '0.1',
            'positionSide' => 'LONG',
        ],
    ], 200),
]);

// ❌ WRONG: Internal format will bypass mapper
Http::fake([
    '*//fapi/*/positionRisk*' => Http::response([
        [
            'symbol' => 'BTC/USDT',  // Internal format - WRONG!
            // ...
        ],
    ], 200),
]);

// ApiDataMapper transforms raw to internal:
// MapsPositionsQuery: 'BTCUSDT' → 'BTC/USDT'
// MapsOrderQuery: 'BTCUSDT' → ['base' => 'BTC', 'quote' => 'USDT']
```

#### Wildcard Patterns for Multiple Endpoints
Use `'*'` when a test calls multiple endpoints:

```php
// ❌ SPECIFIC: Only matches exact pattern, will miss GET/POST variations
Http::fake([
    '*/fapi/v1/order' => Http::response([...], 200),
]);

// ✓ WILDCARD: Matches all endpoints
Http::fake([
    '*' => Http::response([...], 200),
]);
```

#### Response Sequences with Fallback
For tests that make multiple API calls:

```php
Http::fake([
    '*' => Http::sequence()
        ->push(['orderId' => 111, 'status' => 'NEW', ...], 200)  // 1st call
        ->push(['orderId' => 111, 'status' => 'NEW', ...], 200)  // 2nd call
        ->push(['orderId' => 111, 'status' => 'FILLED', ...], 200)  // 3rd call
        ->whenEmpty(Http::response([  // Fallback for additional calls
            'orderId' => 111,
            'status' => 'FILLED',
            ...
        ], 200)),
]);
```

### Namespace Consistency

**ALWAYS** use `Martingalian\Core\Models` namespace for Core models:

```php
// ❌ WRONG: Old namespace
use App\Models\ExchangeSymbol;

// ✓ CORRECT: Core namespace
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Models\Quote;
```

### ExchangeSymbol Accessor Pattern

The `HasAccessors` trait in ExchangeSymbol provides `parsed_trading_pair`:

```php
// Correct field access
$exchangeSymbol->quote->canonical  // ✓ 'canonical' field on Quote model
$exchangeSymbol->symbol->token     // ✓ 'token' field on Symbol model

// Common mistakes caught during testing
$exchangeSymbol->quote->token      // ❌ Quote doesn't have 'token'
$exchangeSymbol->symbol->canonical // ❌ Symbol doesn't have 'canonical'
```

### Complete Helper Function Template

```php
/**
 * Helper function for integration tests.
 * Creates all required models with proper relationships.
 */
function createTestAccountAndSymbol(): array
{
    // 1. Create user (can't use test()->user)
    $user = User::factory()->create();

    // 2. Use firstOrCreate for shared data
    $apiSystem = ApiSystem::firstOrCreate([
        'canonical' => 'binance',
    ], []);

    // 3. Create Quote (not Symbol!)
    $quote = Quote::firstOrCreate([
        'canonical' => 'usdt',
    ], [
        'name' => 'Tether',
    ]);

    // 4. Create Symbol
    $base = Symbol::firstOrCreate([
        'token' => 'BTC',
    ], [
        'name' => 'Bitcoin',
        'cmc_id' => 1,
    ]);

    // 5. Create Account with required fields
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => $user->id,
        'trade_configuration_id' => 1,  // Required!
        'is_active' => true,
        'can_trade' => true,
        'binance_api_key' => 'test-key',
        'binance_api_secret' => 'test-secret',
    ]);

    // 6. Create ExchangeSymbol with both symbol_id and quote_id
    $exchangeSymbol = ExchangeSymbol::create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $base->id,        // Base asset
        'quote_id' => $quote->id,        // Quote asset
        'price_precision' => 2,
        'quantity_precision' => 3,
        'min_notional' => '5.00',
        'tick_size' => '0.01',
        'min_price' => '0.01',
        'max_price' => '999999.00',
        'symbol_information' => ['pair' => 'BTCUSDT'],
        'total_limit_orders' => 4,
        'mark_price' => '50000.00',
        'percentage_gap_long' => '8.50',
        'percentage_gap_short' => '9.50',
    ]);

    return [$account, $exchangeSymbol];
}
```

## Best Practices

### 1. Test Names
Use descriptive, human-readable test names:
```php
// Good
it('sends high priority email for critical notifications')

// Bad
it('test email priority')
```

### 2. Arrange-Act-Assert
Structure tests clearly:
```php
it('calculates total correctly', function () {
    // Arrange
    $cart = new ShoppingCart();
    $cart->addItem(new Item('Product', 10.00));

    // Act
    $total = $cart->getTotal();

    // Assert
    expect($total)->toBe(10.00);
});
```

### 3. Test One Thing
Each test should verify one behavior:
```php
// Good
it('validates email format')
it('validates email uniqueness')

// Bad
it('validates email') // Too broad
```

### 4. Use Factories
Never hardcode test data:
```php
// Good
$user = User::factory()->create();

// Bad
$user = new User([
    'name' => 'Test',
    'email' => 'test@example.com',
    // ...
]);
```

### 5. Clean Up
Use `RefreshDatabase` or transactions:
```php
uses(RefreshDatabase::class);

// No manual cleanup needed
```

### 6. Test Happy & Unhappy Paths
```php
it('creates order successfully'); // Happy path
it('fails when product is out of stock'); // Unhappy path
it('fails when payment declined'); // Unhappy path
```

### 7. Avoid Test Interdependence
Tests must run independently:
```php
// Bad - tests depend on execution order
it('creates user') // Test 1
it('updates user') // Test 2 assumes Test 1 ran

// Good - each test is self-contained
it('creates user', function () {
    $user = User::factory()->create();
    // ...
});

it('updates user', function () {
    $user = User::factory()->create(); // Own setup
    // ...
});
```

### 8. Mock External Services
Never hit real APIs in tests:
```php
Http::fake([
    'api.binance.com/*' => Http::response(['price' => '50000'], 200),
]);
```

### 9. Test Edge Cases
```php
it('handles empty cart')
it('handles negative quantities')
it('handles very large numbers')
it('handles special characters')
it('handles null values')
```

### 10. Use Assertions Wisely
```php
// Prefer specific assertions
$response->assertSuccessful(); // Good
$response->assertStatus(200); // Less specific

$response->assertForbidden(); // Good
$response->assertStatus(403); // Less specific
```

## Common Testing Issues

### Issue: Tests Pass but Production Fails
**Solution**: Use integration tests with real rendering (not mocks/fakes)

### Issue: Notifications Sent During Tests
**Solution**: Fake notifications or use IntegrationTestCase with HTTP fakes

### Issue: Slow Tests
**Solution**:
- Use `RefreshDatabase` instead of migrations
- Mock external services
- Use in-memory database
- Run tests in parallel

### Issue: Flaky Tests
**Solution**:
- Avoid time-dependent logic
- Use `Carbon::setTestNow()`
- Avoid race conditions
- Don't depend on execution order

### Issue: Database State Pollution
**Solution**: Always use `RefreshDatabase` or `DatabaseTransactions`

## Coverage Goals

- **Overall**: 80%+ code coverage
- **Critical paths**: 100% (notifications, payments, trading)
- **Edge cases**: All error scenarios tested
- **Integration**: All user-facing features tested

## Test Maintenance

### When Adding Features
1. Write tests FIRST (TDD) or immediately after
2. Test happy path + error paths
3. Update integration tests if UI changes
4. Update browser tests if JavaScript changes

### When Fixing Bugs
1. Write test that reproduces bug
2. Verify test fails
3. Fix bug
4. Verify test passes
5. Keep test for regression prevention

### When Refactoring
1. Run full test suite before refactoring
2. Refactor code
3. Run full test suite after
4. All tests must still pass

## Testing Workflow

### During Development
```bash
# Quick feedback loop: Run only the tests you're working on
php artisan test --filter="test name"
php artisan test tests/Feature/YourTest.php

# Auto-fix code style issues
composer lint

# Before committing: Run unit tests + lint
composer test:unit
composer test:lint
```

### Before Pull Request
```bash
# Run the full test suite
composer test

# This ensures:
# ✓ All unit/feature/integration tests pass
# ✓ Type coverage is 100%
# ✓ Code style is correct (Pint + Rector + Prettier)
# ✓ Static analysis passes (PHPStan level max)
```

### Continuous Integration
```bash
# CI runs the same command
composer test

# On failure, check specific areas:
composer test:unit          # If tests fail
composer test:integration   # If integration tests fail
composer test:types         # If PHPStan fails
composer test:lint          # If code style fails
```

### Quick Commands Reference
```bash
composer test              # Full suite (use before PR)
composer test:unit         # Fast feedback (use during dev)
composer test:integration  # Integration only
composer test:types        # PHPStan static analysis
composer test:lint         # Verify code style
composer lint              # Auto-fix code style
```

## Future Testing Enhancements

- Visual regression testing (Pest v4)
- Load testing (k6, Locust)
- E2E testing (full user journeys)
- Contract testing (API consumers)
- Security testing (SQL injection, XSS)
- Performance testing (slow queries)
