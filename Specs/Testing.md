# Testing Strategy

## Overview
Comprehensive testing using **Pest v4** with feature tests, unit tests, integration tests, and browser tests. Current status: **753 tests, 2946 assertions, all passing**.

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
uses(TestCase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Feature/Database');
uses(IntegrationTestCase::class, RefreshDatabase::class)->in('Integration');

function fakeApis(): void
{
    Http::fake([
        'api.binance.com/*' => Http::response(['price' => '50000'], 200),
        'api.bybit.com/*' => Http::response(['result' => []], 200),
        'api.taapi.io/*' => Http::response(['value' => 50], 200),
    ]);
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

### Running Integration Tests

Integration tests cannot run in parallel (they share log files):

```bash
# Run integration tests only
composer test:integration
php artisan test --testsuite=Integration

# Run all tests (includes integration sequentially)
php artisan test

# Unit tests in parallel (excludes integration)
composer test:unit
```

## Running Tests

### All Tests
```bash
php artisan test
```

### Specific Suite
```bash
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration
```

### Specific File
```bash
php artisan test tests/Feature/Notifications/AlertNotificationTest.php
```

### Specific Test
```bash
php artisan test --filter="renders alert email"
php artisan test --filter=testUserCanLogin
```

### With Coverage
```bash
php artisan test --coverage
php artisan test --coverage --min=80
```

### Parallel Execution
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

## Future Testing Enhancements

- Visual regression testing (Pest v4)
- Load testing (k6, Locust)
- E2E testing (full user journeys)
- Contract testing (API consumers)
- Security testing (SQL injection, XSS)
- Performance testing (slow queries)
