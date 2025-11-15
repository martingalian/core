# Email Templates

## Overview
Professional HTML email templates for alert notifications. Fully responsive, multi-client compatible, with inline CSS styling. Supports severity indicators, action buttons, and rich formatting.

## Template Location
`resources/views/emails/notification.blade.php`

## Design Principles

1. **Professional & Clean**: Modern design with clear hierarchy
2. **Accessible**: High contrast, readable fonts, semantic HTML
3. **Responsive**: Works on desktop and mobile email clients
4. **User-Friendly**: Easy to scan, copy-paste friendly formatting
5. **Branded**: Martingalian branding with consistent colors

## Email Structure

### 1. Header
- Martingalian logo/branding
- Brand color background (#3b82f6 - blue)
- White text

### 2. Content Area
- Salutation: "Hello {User Name},"
- Severity badge (if provided)
- Notification title (h2)
- Message content (with newlines preserved)
- Details box (if provided)
- Action button (if provided)

### 3. Signature
- "Best regards,"
- "Martingalian Team"

### 4. Footer
- Support email link (info@martingalian.com)
- Timestamp with timezone (Europe/Zurich)
- Server hostname (for identifying which server sent the notification)
- Light gray background

## Email Subject Construction

**User Notifications** (MAY include server context):
- Base: Notification title
- If serverIp and exchange provided: `"Title - Server IP on Exchange"`
- Example: `"IP Whitelist Required - Server 1.2.3.4 on Binance"`

**Admin Notifications** (NO server context):
- Base: Notification title only
- No server IP or exchange appended
- Example: `"API Rate Limit Exceeded"` (clean and focused)
- Server context (when relevant) appears in email body, not subject

**Rationale**: Admin email subjects stay clean and focused on issue type, not infrastructure. This makes email filtering, searching, and inbox management easier. Server context (when relevant) goes in the email body.

## Template Code

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notificationTitle }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #3b82f6;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 30px;
        }
        .salutation {
            margin-bottom: 20px;
            font-size: 16px;
            color: #333;
        }
        .severity-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        .notification-title {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 20px 0;
        }
        .message {
            font-size: 15px;
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 20px;
        }
        .details-box {
            background-color: #f9fafb;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
            color: #4b5563;
        }
        .details-box strong {
            display: block;
            margin-bottom: 10px;
            color: #1f2937;
        }
        .action-button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #3b82f6;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .action-button:hover {
            background-color: #2563eb;
        }
        .signature {
            margin-top: 30px;
            font-size: 15px;
            line-height: 1.6;
            color: #4b5563;
        }
        .footer {
            background-color: #f3f4f6;
            padding: 20px;
            text-align: center;
            font-size: 13px;
            color: #6b7280;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .timestamp {
            margin-top: 10px;
            font-size: 12px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>MARTINGALIAN</h1>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Salutation -->
            <div class="salutation">
                Hello {{ $userName ?? 'there' }},
            </div>

            <!-- Severity Badge -->
            @if($severity)
            <span class="severity-badge" style="background-color: {{ $severity->backgroundColor() }}; color: {{ $severity->textColor() }};">
                {{ $severity->icon() }} {{ $severity->label() }}
            </span>
            @endif

            <!-- Title -->
            <h2 class="notification-title">{{ $notificationTitle }}</h2>

            <!-- Message -->
            <div class="message">
                {!! nl2br(e($notificationMessage)) !!}
            </div>

            <!-- Details Box -->
            @if($details)
            <div class="details-box">
                <strong>Additional Details</strong>
                {!! nl2br(e($details)) !!}
            </div>
            @endif

            <!-- Action Button -->
            @if($actionUrl && $actionLabel)
            <a href="{{ $actionUrl }}" class="action-button">{{ $actionLabel }}</a>
            @endif

            <!-- Signature -->
            <div class="signature">
                Best regards,<br/>
                Martingalian Team
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                Need help? Contact us at <a href="mailto:info@martingalian.com">info@martingalian.com</a>
            </p>
            <p class="timestamp">
                Time sent: {{ now()->format('H:i') }} ({{ config('app.timezone') }})<br/>
                Server: {{ $hostname ?? gethostname() }}
            </p>
        </div>
    </div>
</body>
</html>
```

## Template Variables

### Required Variables

- `$notificationTitle` (string): Email subject and main title
- `$notificationMessage` (string): Main message content

### Optional Variables

- `$severity` (NotificationSeverity|null): Severity level for badge
- `$actionUrl` (string|null): CTA button URL
- `$actionLabel` (string|null): CTA button text
- `$details` (string|null): Additional technical details
- `$hostname` (string|null): Server hostname (fallback to gethostname())
- `$userName` (string|null): User's name for salutation (fallback to "there")

## Severity Badge

### Severity Enum
Located: `app/Enums/NotificationSeverity.php`

```php
enum NotificationSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Info = 'info';

    public function label(): string
    {
        return match($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Info => 'Info',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Critical => '🔴',
            self::High => '🟠',
            self::Medium => '🟡',
            self::Info => '🔵',
        };
    }

    public function backgroundColor(): string
    {
        return match($this) {
            self::Critical => '#dc2626', // Red
            self::High => '#ea580c',     // Orange
            self::Medium => '#ca8a04',   // Yellow
            self::Info => '#2563eb',     // Blue
        };
    }

    public function textColor(): string
    {
        return '#ffffff'; // White text for all badges
    }
}
```

### Badge Display
```html
<span class="severity-badge" style="background-color: #dc2626; color: #ffffff;">
    🔴 CRITICAL
</span>
```

## Message Formatting

### Newlines
Messages use `nl2br(e())` to convert newlines to `<br/>` tags while escaping HTML:

```php
// Input
$message = "Line 1\nLine 2\nLine 3";

// Output in email
Line 1<br/>
Line 2<br/>
Line 3
```

### Special Markup

#### [COPY] Marker - Prominent IP Addresses
Use `[COPY]text[/COPY]` to render IP addresses prominently with large, bold, monospace font:

```php
$message = "Server IP Address:\n[COPY]192.168.1.1[/COPY]\n\nPlease whitelist this IP.";

// Renders as prominent, user-selectable monospace text (20px, Courier New)
```

**CSS Styling**:
```css
.ip-address {
    font-family: 'Courier New', Courier, monospace;
    font-size: 20px;
    font-weight: 700;
    user-select: all; /* Easy to select/copy */
}
```

#### [CMD] Marker - Command Blocks
Use `[CMD]command[/CMD]` to render system commands with monospace font and distinctive styling:

```php
$message = "Check supervisor status:\n[CMD]supervisorctl status update-binance-prices[/CMD]\n\nReview logs:\n[CMD]tail -100 storage/logs/laravel.log[/CMD]";

// Renders as bold monospace command blocks with blue left border
```

**CSS Styling**:
```css
.command-block {
    font-family: 'Courier New', Courier, Consolas, Monaco, monospace;
    font-size: 14px;
    font-weight: 700;
    background-color: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-left: 4px solid #3b82f6;
    padding: 12px 16px;
    user-select: all; /* Easy to select/copy */
}
```

### Copy-Paste Friendly
Important data on separate lines:

```php
$message = "Server IP Address:\n{$ip}\n\nPlease whitelist this IP.";

// Renders as:
Server IP Address:
192.168.1.1

Please whitelist this IP.
```

### HTML Escaping
All user content is escaped to prevent XSS:

```php
// Input
$message = "Test <script>alert('xss')</script> message";

// Output (safe)
Test &lt;script&gt;alert('xss')&lt;/script&gt; message
```

## Email Priority Headers

For Critical and High severity notifications, the email includes priority headers:

```php
// In AlertMail::envelope()
if ($this->severity && in_array($this->severity, [NotificationSeverity::Critical, NotificationSeverity::High])) {
    $envelope->using(function (\Symfony\Component\Mime\Email $message) {
        $message->priority(\Symfony\Component\Mime\Email::PRIORITY_HIGH);
        $message->getHeaders()
            ->addTextHeader('X-Priority', '1')
            ->addTextHeader('Importance', 'high');
    });
}
```

**Result**: Email clients show red exclamation mark or "Important" indicator.

## Color Palette

### Brand Colors
- Primary Blue: `#3b82f6`
- Dark Blue: `#2563eb`

### Severity Colors
- Critical Red: `#dc2626`
- High Orange: `#ea580c`
- Medium Yellow: `#ca8a04`
- Info Blue: `#2563eb`

### Text Colors
- Dark Gray (headings): `#1f2937`
- Medium Gray (body): `#4b5563`
- Light Gray (meta): `#6b7280`
- Extra Light Gray (timestamp): `#9ca3af`

### Background Colors
- White (main): `#ffffff`
- Light Gray (footer): `#f3f4f6`
- Extra Light Gray (details box): `#f9fafb`
- Off-White (page): `#f5f5f5`

## Typography

### Font Stack
```css
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
```

System fonts for fast loading and native look.

### Font Sizes
- Header: 24px
- Title: 20px
- Body: 15px
- Small: 13px
- Tiny: 12px

### Line Heights
- Body text: 1.6 (readable)
- Headings: default (compact)

## Responsive Design

### Max Width
Email container: 600px (optimal for most email clients)

### Mobile Considerations
- Touch-friendly button size (44px min)
- Readable font sizes (15px body minimum)
- Adequate padding for touch targets
- No fixed widths in critical areas

## Email Client Compatibility

### Tested Clients
- Gmail (web, mobile)
- Outlook (desktop, web)
- Apple Mail (macOS, iOS)
- Thunderbird
- Yahoo Mail
- ProtonMail

### Compatibility Techniques
1. **Inline CSS**: All styles inline for maximum compatibility
2. **Table-based layout**: (not used, but optional for problematic clients)
3. **Fallback fonts**: System font stack
4. **No external resources**: All styles embedded
5. **Safe HTML**: Supported tags only

### Limitations
- No CSS Grid (not widely supported)
- No complex animations
- No external images (logo is text-based)
- Limited font options

## Testing Emails

### Integration Tests
Located: `tests/Integration/Mail/AlertNotificationEmailTest.php`

Tests validate:
- Email rendering
- All components present
- Correct HTML structure
- Security (HTML escaping)
- Newline handling
- Severity badges
- Action buttons
- Signature and footer

### Test Helpers
```php
// Assert email sent
$this->assertEmailWasSent();

// Get email HTML
$html = $this->getLastEmailHtml();

// Assert content
$this->assertLastEmailContains('Expected text');

// Assert valid HTML
$this->assertLastEmailHasValidHtml();
```

### Manual Testing
```bash
# Send test email
php artisan tinker

NotificationService::sendToUser(
    user: User::first(),
    message: 'Test message',
    title: 'Test Alert',
    severity: NotificationSeverity::Critical
);
```

## Common Use Cases

### 1. IP Whitelist Required
```php
NotificationService::sendToUser(
    user: $user,
    message: "We noticed that our server isn't whitelisted...\n\nServer IP Address:\n192.168.1.1\n\n...",
    title: 'IP Whitelist Required',
    severity: NotificationSeverity::Critical,
    actionUrl: 'https://www.binance.com/en/my/settings/api-management',
    actionLabel: 'Update IP Whitelist'
);
```

### 2. Rate Limit Exceeded
```php
NotificationService::sendToUser(
    user: $user,
    message: "We're temporarily making too many requests...",
    title: 'Rate Limit Reached',
    severity: NotificationSeverity::High,
    actionUrl: 'https://www.binance.com/en/support/announcement/system',
    actionLabel: 'View Exchange Status'
);
```

### 3. P&L Alert
```php
NotificationService::sendToUser(
    user: $user,
    message: "Your unrealized profit/loss has exceeded 10%...\n\nAccount:\nMy Account\n\nWallet Balance:\n$10,000\n\n...",
    title: 'Profit & Loss Alert',
    severity: NotificationSeverity::Info
);
```

### 4. System Error
```php
NotificationService::sendToAdmin(
    message: "An unexpected error occurred...",
    title: 'System Error',
    severity: NotificationSeverity::Critical,
    additionalParameters: [
        'details' => "Exception: Division by zero\nFile: Calculator.php:45\nLine: 45",
    ]
);
```

## Best Practices

### 1. Keep Messages Concise
- Get to the point quickly
- Use short paragraphs
- Break up long text with blank lines

### 2. Use Severity Appropriately
- **Critical**: Requires immediate action
- **High**: Important but not urgent
- **Medium**: Informational, may require action
- **Info**: FYI only

### 3. Include Context
- What happened?
- Why does it matter?
- What should the user do?

### 4. Make Data Copy-Paste Friendly
```php
// Good: Data on separate line
"Server IP:\n192.168.1.1"

// Bad: Data buried in text
"The server IP is 192.168.1.1 and needs..."
```

### 5. Use Action Buttons Wisely
- Provide direct link to solution
- Use clear, action-oriented labels
- Only include if actionable

### 6. Test Before Sending
- Preview in multiple clients
- Check on mobile
- Verify all links work
- Test with different content lengths

## Accessibility

### Screen Readers
- Semantic HTML (h1, h2, p)
- Descriptive link text (not "click here")
- Alt text for images (if added)

### Color Contrast
- All text meets WCAG AA standards
- Severity badges use high contrast
- Links clearly distinguishable

### Keyboard Navigation
- All links focusable
- Logical tab order
- No keyboard traps

## Future Enhancements

- Dark mode support (prefers-color-scheme)
- Inline images (logo, charts)
- Rich formatting (tables, lists)
- Template variants (transaction receipts, reports)
- Localization support (multi-language)
- RTL language support
