# Email Templates

## Overview

Professional HTML email templates for alert notifications. Fully responsive, multi-client compatible, with inline CSS styling. Supports severity indicators, action buttons, and rich formatting.

---

## Template Location

`resources/views/emails/notification.blade.php`

---

## Design Principles

1. **Professional & Clean**: Modern design with clear hierarchy
2. **Accessible**: High contrast, readable fonts, semantic HTML
3. **Responsive**: Works on desktop and mobile email clients
4. **User-Friendly**: Easy to scan, copy-paste friendly formatting
5. **Branded**: Martingalian branding with consistent colors

---

## Email Structure

### Header
- Martingalian branding
- Brand color background (#3b82f6 - blue)
- White text

### Content Area
- Salutation: "Hello {User Name},"
- Severity badge (if provided)
- Notification title (h2)
- Message content (with newlines preserved)
- Details box (if provided)
- Action button (if provided)

### Signature
- "Best regards,"
- "Martingalian Team"

### Footer
- Support email link
- Timestamp with timezone (Europe/Zurich)
- Server hostname

---

## Email Subject Construction

| Recipient | Format | Example |
|-----------|--------|---------|
| User | Title + server context (if applicable) | "IP Whitelist Required - Server 1.2.3.4 on Binance" |
| Admin | Title only (clean) | "API Rate Limit Exceeded" |

**Rationale**: Admin subjects stay clean for filtering/searching. Server context goes in email body.

---

## Template Variables

### Required

| Variable | Type | Description |
|----------|------|-------------|
| `$notificationTitle` | string | Email subject and main title |
| `$notificationMessage` | string | Main message content |

### Optional

| Variable | Type | Description |
|----------|------|-------------|
| `$severity` | NotificationSeverity | Severity level for badge |
| `$actionUrl` | string | CTA button URL |
| `$actionLabel` | string | CTA button text |
| `$details` | string | Additional technical details |
| `$hostname` | string | Server hostname |
| `$userName` | string | User's name for salutation |

---

## Severity Badge

### Severity Enum

| Level | Label | Icon | Background | Text |
|-------|-------|------|------------|------|
| Critical | CRITICAL | 🔴 | #dc2626 (red) | #ffffff |
| High | HIGH | 🟠 | #ea580c (orange) | #ffffff |
| Medium | MEDIUM | 🟡 | #ca8a04 (yellow) | #ffffff |
| Info | INFO | 🔵 | #2563eb (blue) | #ffffff |

---

## Message Formatting

### Newlines
Messages use `nl2br(e())` to convert newlines to `<br/>` tags while escaping HTML.

### Special Markup

| Marker | Purpose | Rendering |
|--------|---------|-----------|
| `[COPY]text[/COPY]` | IP addresses | Large, bold, monospace (20px) |
| `[CMD]text[/CMD]` | Commands | Monospace with blue border |

### HTML Escaping
All user content is escaped to prevent XSS.

---

## Email Priority Headers

For Critical and High severity:
- `X-Priority: 1`
- `Importance: high`

**Result**: Email clients show priority indicator.

---

## Color Palette

### Brand Colors
| Color | Hex | Usage |
|-------|-----|-------|
| Primary Blue | #3b82f6 | Headers, buttons, accents |
| Dark Blue | #2563eb | Hover states |

### Severity Colors
| Severity | Hex |
|----------|-----|
| Critical | #dc2626 |
| High | #ea580c |
| Medium | #ca8a04 |
| Info | #2563eb |

### Text Colors
| Usage | Hex |
|-------|-----|
| Headings | #1f2937 |
| Body | #4b5563 |
| Meta | #6b7280 |
| Timestamp | #9ca3af |

### Background Colors
| Usage | Hex |
|-------|-----|
| Main | #ffffff |
| Footer | #f3f4f6 |
| Details box | #f9fafb |
| Page | #f5f5f5 |

---

## Typography

### Font Stack
System fonts: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif

### Font Sizes
| Element | Size |
|---------|------|
| Header | 24px |
| Title | 20px |
| Body | 15px |
| Small | 13px |
| Tiny | 12px |

---

## Responsive Design

| Setting | Value |
|---------|-------|
| Max width | 600px |
| Touch target | 44px minimum |
| Body font | 15px minimum |

---

## Email Client Compatibility

### Tested Clients
Gmail, Outlook (desktop/web), Apple Mail (macOS/iOS), Thunderbird, Yahoo Mail, ProtonMail

### Compatibility Techniques
1. Inline CSS for maximum compatibility
2. System font stack
3. All styles embedded
4. Safe HTML tags only

### Limitations
- No CSS Grid
- No complex animations
- No external images
- Limited font options

---

## Testing

### Integration Tests
Location: `tests/Integration/Mail/AlertNotificationEmailTest.php`

### Test Coverage
- Email rendering
- All components present
- Correct HTML structure
- Security (HTML escaping)
- Newline handling
- Severity badges
- Action buttons
- Signature and footer

---

## Common Use Cases

| Use Case | Severity | Action Button |
|----------|----------|---------------|
| IP Whitelist Required | Critical | Update IP Whitelist |
| Rate Limit Exceeded | High | View Exchange Status |
| P&L Alert | Info | None |
| System Error | Critical | None (admin) |

---

## Best Practices

| Practice | Reason |
|----------|--------|
| Keep messages concise | Easy to scan |
| Use severity appropriately | Clear priority |
| Include context | What, why, what to do |
| Data on separate lines | Easy copy-paste |
| Clear action labels | Actionable |
| Test before sending | Verify rendering |

---

## Accessibility

| Feature | Implementation |
|---------|----------------|
| Screen readers | Semantic HTML (h1, h2, p) |
| Color contrast | WCAG AA compliant |
| Keyboard navigation | All links focusable |
| Descriptive links | Not "click here" |

---

## Related Systems

- **NotificationService**: Sends notifications
- **AlertNotification**: Notification class
- **NotificationSeverity**: Severity enum
- **IntegrationTestCase**: Email testing
