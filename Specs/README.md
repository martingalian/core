# Martingalian Specs Documentation

## Overview
This directory contains comprehensive technical documentation for the Martingalian cryptocurrency trading automation system. These specs serve as a knowledge base for understanding the codebase architecture, design decisions, and implementation details.

## Purpose
- **Onboarding**: Quickly get new developers up to speed
- **Reference**: Look up specific implementation details
- **Design Decisions**: Understand why things are built the way they are
- **Best Practices**: Follow established patterns and conventions
- **Troubleshooting**: Diagnose issues and find solutions

## Documentation Files

### [Architecture.md](Architecture.md)
**Read this first!**

High-level system architecture covering:
- Technology stack (Laravel 12, PHP 8.4, Horizon, WebSockets)
- Directory structure and organization
- Job architecture (BaseQueueableJob, BaseApiableJob, steps)
- Command architecture (cronjobs, admin, testing)
- Configuration management
- Deployment procedures
- Monitoring and health checks
- Important conventions (dos and don'ts)

**When to read**: Starting new features, understanding system flow, deployment planning

### [DatabaseSchema.md](DatabaseSchema.md)
Complete database schema reference:
- All table structures (users, exchange_accounts, positions, orders, etc.)
- Relationships and foreign keys
- Migration patterns
- Eloquent model conventions
- Query best practices
- Transaction handling and locking
- Common queries
- Performance considerations

**When to read**: Working with models, creating migrations, optimizing queries

### [Notifications.md](Notifications.md)
Notification system architecture:
- Multi-channel support (Pushover, Email)
- AlertNotification class
- NotificationService layer
- NotificationMessageBuilder (user-friendly messages)
- Throttling to prevent spam
- Severity levels (Critical, High, Medium, Info)
- User preferences
- Delivery groups
- Testing strategies

**When to read**: Sending notifications, handling errors, creating user-friendly messages

### [ApiClients.md](ApiClients.md)
External API integration layer:
- Exchange clients (Binance, Bybit REST + WebSocket)
- Market data providers (TAAPI, CoinMarketCap, Alternative.me)
- Exception handlers
- Rate limiting strategies
- WebSocket architecture (Ratchet, ReactPHP)
- Configuration
- Testing approaches
- Common issues and solutions

**When to read**: Working with exchange APIs, handling API errors, WebSocket development

### [StepDispatcher.md](StepDispatcher.md)
Step-based job execution system:
- Step model and state machine
- Parent-child dependencies
- State transitions (Pending, Running, Completed, Failed, etc.)
- Dispatcher execution flow
- Retry logic and backoff
- Business rules and constraints
- Testing patterns

**When to read**: Working with jobs, implementing background tasks, debugging step execution

### [Testing.md](Testing.md)
Comprehensive testing strategy:
- Test types (Feature, Unit, Integration, Browser)
- Pest v4 framework usage
- Test organization
- Testing patterns (factories, mocking, assertions)
- Integration testing with real rendering
- Running tests
- Best practices
- Common testing issues

**When to read**: Writing tests, debugging test failures, ensuring code quality

### [EmailTemplates.md](EmailTemplates.md)
Email template system:
- HTML email structure
- Template variables
- Severity badges
- Message formatting (newlines, copy-paste friendly)
- Email priority headers
- Color palette and typography
- Email client compatibility
- Testing emails
- Common use cases

**When to read**: Modifying email templates, designing notifications, testing email rendering

## Quick Reference

### Common Tasks

#### Starting Development
1. Read [Architecture.md](Architecture.md) - system overview
2. Review [DatabaseSchema.md](DatabaseSchema.md) - data models
3. Check [Testing.md](Testing.md) - test expectations

#### Adding a Feature
1. Check [Architecture.md](Architecture.md) - follow conventions
2. Review [DatabaseSchema.md](DatabaseSchema.md) - understand models
3. Write tests first ([Testing.md](Testing.md))
4. Implement feature
5. Update relevant spec docs

#### Handling API Errors
1. Check [ApiClients.md](ApiClients.md) - exception handlers
2. Use [NotificationMessageBuilder.md](Notifications.md) - user-friendly messages
3. Follow throttling patterns
4. Test with integration tests

#### Modifying Notifications
1. Review [Notifications.md](Notifications.md) - system architecture
2. Check [EmailTemplates.md](EmailTemplates.md) - template structure
3. Update [NotificationMessageBuilder](Notifications.md) - message content
4. Write integration tests

#### Working with Database
1. Read [DatabaseSchema.md](DatabaseSchema.md) - schema reference
2. Check [Architecture.md](Architecture.md) - migration location (packages/martingalian/core)
3. Use transactions and locking
4. Test with RefreshDatabase

#### Debugging Production Issues
1. Check logs: `storage/logs/laravel.log`
2. Review Horizon dashboard: `/horizon`
3. Check [ApiClients.md](ApiClients.md) - common issues
4. Review [Notifications.md](Notifications.md) - throttling

## Documentation Standards

### When to Update Specs

Update specs when:
- Adding new major features
- Changing architecture patterns
- Discovering important design decisions
- Fixing critical bugs with architectural implications
- Adding new external integrations
- Changing database schema significantly

### What to Document

Document:
- **Why** decisions were made (not just what)
- **Gotchas** and common mistakes
- **Best practices** and patterns
- **Examples** of usage
- **Common issues** and solutions
- **Testing strategies**

Don't document:
- Trivial implementation details
- Self-explanatory code
- Temporary workarounds
- Incomplete features

### Documentation Style

- **Clear and concise**: Get to the point quickly
- **Examples**: Show code examples for complex concepts
- **Structure**: Use headings, lists, and tables
- **Links**: Cross-reference related specs
- **Updated**: Keep docs in sync with code

## Spec Maintenance

### Review Cycle
- Review specs quarterly
- Update after major releases
- Validate against codebase
- Remove outdated sections

### Contribution Guidelines
When updating specs:
1. Follow existing format and style
2. Add examples for complex topics
3. Update table of contents
4. Cross-reference related docs
5. Mark outdated information

### Feedback
Found outdated info or missing docs? Open an issue or update directly.

## Version History

### 2025-01-31
- Added StepDispatcher.md documentation
- Updated test counts (753 tests, 2946 assertions)
- Fixed database field references (pushover_key)
- Removed references to deleted test files
- Quality status: 100% tests passing, 0 PHPStan errors

### 2025-01-30
- Initial specs created
- Covered: Architecture, Database, Notifications, API Clients, Testing, Email Templates
- Status: Up to date with codebase

## Additional Resources

### External Documentation
- [Laravel 12 Docs](https://laravel.com/docs/12.x)
- [Pest v4 Docs](https://pestphp.com/docs)
- [Horizon Docs](https://laravel.com/docs/12.x/horizon)
- [Binance API Docs](https://binance-docs.github.io/apidocs/)
- [Bybit API Docs](https://bybit-exchange.github.io/docs/)
- [TAAPI Docs](https://taapi.io/documentation)

### Code Quality Tools
- **Pint**: `vendor/bin/pint --dirty` - Code formatting (Laravel style)
- **Larastan**: `composer test:types` - Static analysis (0 errors)
- **Pest**: `php artisan test` - Feature/integration tests (753 passing)
- **Unit Tests**: `composer test:unit` - Isolated unit tests (702 passing)

### Development Commands
```bash
# Start development servers
php artisan serve
php artisan horizon
npm run dev

# Run tests
php artisan test
php artisan test --filter=testName

# Code quality
vendor/bin/pint
vendor/bin/phpstan analyse

# Database
php artisan migrate
php artisan db:seed
php artisan tinker

# Clear caches
php artisan optimize:clear
```

## Contact
For questions or clarifications about the specs, contact the development team.
