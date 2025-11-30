# Analytics Dashboard Architecture

## Overview

The Analytics dashboard (`/analytics`) is a Turbo-based architecture for real-time monitoring and system management. It uses lazy-loaded tabs with independent, self-refreshing components.

## Technology Stack

- **Turbo (Hotwire)**: Lazy-loading tab content via Turbo Frames
- **Vite**: Asset bundling (JS/CSS)
- **Tailwind CSS v4**: Styling
- **Chart.js**: Timeline charts (Analytics tab)
- **Vanilla JS**: Component data fetching and rendering

## Architecture

### Component Hierarchy

```
/analytics (main layout)
  └── Tab (loaded via Turbo Frame)
        └── Master Component (has controller, fetches metadata)
              └── Child UI Components (pure UI, self-sufficient after init)
```

### Component Types

| Type | Has Controller | Fetches Data | Responsibilities |
|------|----------------|--------------|------------------|
| Tab | No (just includes components) | No | Container for master components |
| Master Component | Yes | Yes (metadata) | Fetch config/metadata, render child UI components |
| Child UI Component | No | Yes (its own data) | Self-sufficient, manages own refresh cycle |

### Data Flow

1. User clicks tab → Turbo loads tab content
2. Tab includes master component(s)
3. Master component renders skeleton
4. Master component fetches metadata from its API endpoint
5. Master component renders N child UI components with config
6. Each child UI component:
   - Receives initialization data (endpoint, refresh interval, etc.)
   - Fetches its own data from its endpoint
   - Manages its own refresh cycle
   - Updates its own UI

## File Structure

### Controllers

```
app/Controllers/Analytics/
├── TabController.php                          # Tab routing
└── Components/
    ├── Health/
    │   └── ServersStatusController.php        # Returns server list + endpoints + GitHub commits
    ├── Analytics/
    │   └── CompletedStepsTimelineController.php  # Timeline chart data with bucket caching
    ├── Dispatcher/
    │   ├── HeaderControlsController.php       # Cooling toggle controls
    │   ├── TotalStatsController.php           # Aggregate step metrics
    │   └── HostnameStatsController.php        # Per-hostname breakdown
    ├── Tables/
    │   └── TableCountsController.php          # Table counts with letter grouping
    └── Artisan/
        └── ArtisanCommandsController.php      # Available commands + execution
```

### Views

```
resources/views/analytics/
├── index.blade.php                            # Main layout with Turbo Frame
├── partials/
│   └── _header.blade.php                      # Shared header
└── tabs/
    ├── analytics/
    │   ├── index.blade.php                    # Tab container
    │   └── components/
    │       └── completed-steps-timeline.blade.php  # Chart.js timeline
    ├── tables/
    │   ├── index.blade.php
    │   └── components/
    │       └── table-counts.blade.php         # Letter-grouped collapsible tables
    ├── health/
    │   ├── index.blade.php
    │   └── components/
    │       └── servers-status.blade.php       # Server tiles with GitHub commit comparison
    ├── dispatcher/
    │   ├── index.blade.php
    │   └── components/
    │       ├── header-controls.blade.php      # Cooling toggle
    │       ├── total-stats.blade.php          # Aggregate metrics
    │       └── hostname-stats.blade.php       # Per-hostname breakdown
    └── artisan/
        ├── index.blade.php
        └── components/
            └── artisan-commands.blade.php     # Command list + execution
```

## Routes

```php
// Main layout
GET /analytics                                  → TabController@index

// Tab content (Turbo Frame)
GET /analytics/tab/{tab}                        → TabController@tab

// Analytics Component APIs
GET /analytics/api/analytics/completed-steps-timeline  → CompletedStepsTimelineController

// Health Component APIs
GET /analytics/api/health/servers-status        → ServersStatusController

// Dispatcher Component APIs
GET /analytics/api/dispatcher/header            → HeaderControlsController
POST /analytics/api/dispatcher/toggle-cooling   → HeaderControlsController@toggle
GET /analytics/api/dispatcher/total-stats       → TotalStatsController
GET /analytics/api/dispatcher/hostname-stats    → HostnameStatsController
GET /analytics/api/dispatcher/hostname-stats/{hostname}  → HostnameStatsController@stats

// Tables Component APIs
GET /analytics/api/tables/counts                → TableCountsController
GET /analytics/api/tables/counts/{table}        → TableCountsController@single

// Artisan Component APIs
GET /analytics/api/artisan/commands             → ArtisanCommandsController
POST /analytics/api/artisan/run                 → ArtisanCommandsController@run
```

## Tabs

| Tab | Description | Master Components |
|-----|-------------|-------------------|
| Analytics | Completed steps timeline chart | completed-steps-timeline |
| Tables | Database table counts grouped by letter | table-counts |
| Health | Server status monitoring with commit comparison | servers-status |
| Dispatcher | Step processing metrics | header-controls, total-stats, hostname-stats |
| Artisan | Run artisan commands | artisan-commands |

---

## Tab: Analytics

### Completed Steps Timeline (`completed-steps-timeline.blade.php`)

A Chart.js bar chart showing completed steps over the last 2 hours in 5-minute intervals.

**Features:**
- 24 buckets (5-minute intervals over 2 hours)
- Current bucket highlighted in cyan, past buckets in pink
- Smart caching: only current bucket queries DB fresh, completed buckets cached for 2 hours
- Smooth bar height transitions on data updates (400ms)
- No initial animation (prevents bars "sliding in")
- Displays total completed count
- Auto-refresh every 10 seconds

**API Response:**
```json
{
  "success": true,
  "data": {
    "timeline": [
      { "time": "2025-11-30 22:00:00", "timestamp": 1732997200, "label": "22:00", "count": 42 },
      { "time": "2025-11-30 22:05:00", "timestamp": 1732997500, "label": "22:05", "count": 38 }
    ],
    "total": 850,
    "start_time": "2025-11-30T20:10:00+01:00",
    "end_time": "2025-11-30T22:10:00+01:00",
    "interval_minutes": 5,
    "current_bucket_label": "22:10",
    "generated_at": "2025-11-30T22:10:15+01:00"
  }
}
```

**Caching Strategy:**
- Completed buckets (not current): cached in Redis/cache for 2 hours
- Current bucket: always queried fresh from DB
- Cache key format: `timeline:bucket:YYYY-MM-DD HH:mm:00`

---

## Tab: Tables

### Table Counts (`table-counts.blade.php`)

Displays all database tables grouped by first letter with collapsible sections.

**Features:**
- MySQL global stats header (database size, connections, threads, uptime, queries/sec)
- Tables grouped alphabetically by first letter (A, B, C, etc.)
- Each letter group shows: table count, total rows, preview of first 3 table names
- Collapsible sections - click to expand/collapse
- Smart refresh: only expanded sections refresh (every 5 seconds)
- Individual table tiles show: name, row count, size, last updated time
- Main data cached for 30 seconds

**API Response (all tables):**
```json
{
  "success": true,
  "data": {
    "tables": [
      { "name": "clients", "rows": 1234, "size": "2.5 MB", "size_bytes": 2621440 },
      { "name": "steps", "rows": 50000, "size": "15.2 MB", "size_bytes": 15938355 }
    ],
    "mysql": {
      "database_size": "38.4 MB",
      "database_size_bytes": 40271872,
      "connections": 7,
      "max_connections": 300,
      "threads_running": 2,
      "uptime": "13h 5m",
      "uptime_seconds": 47134,
      "queries_per_second": 1268.6,
      "tables_count": 42,
      "total_rows": 78651
    },
    "generated_at": "2025-11-30T22:51:39+01:00"
  }
}
```

**API Response (single table):**
```json
{
  "success": true,
  "data": {
    "name": "steps",
    "rows": 50000,
    "size": "15.2 MB",
    "size_bytes": 15938355,
    "generated_at": "2025-11-30T22:51:45+01:00"
  }
}
```

---

## Tab: Health

### Servers Status (`servers-status.blade.php`)

Displays real-time health status for all apiable servers.

**Features:**
- Server tiles with CPU/MEM/DISK gauges (circular progress)
- Status badges: Online (green), Maintenance (yellow), Error (orange), Offline (red)
- Supervisor process status (running/total, fatal count)
- Cron service status (active/inactive)
- Package version comparison with GitHub:
  - `martingalian/core` commit
  - `martingalian/ingestion` commit
  - **Green** if matches GitHub latest, **Red** if outdated
- Uptime display
- Auto-refresh every 10 seconds per tile

**GitHub Commit Comparison:**

The controller fetches the latest commit SHA from GitHub API (source of truth) and compares against what each server reports. This allows detecting servers that haven't been deployed yet.

- Requires `GITHUB_TOKEN` environment variable with `Contents: Read` permission
- Results cached for 60 seconds to avoid rate limiting
- Shows "(latest: abc1234)" next to outdated commits

**API Response:**
```json
{
  "servers": [
    {
      "hostname": "ingestion",
      "ip_address": "46.62.203.165",
      "alive_endpoint": "https://ingestion.martingalian.com/alive",
      "health_endpoint": "https://ingestion.martingalian.com/health-check",
      "refresh_interval": 10000
    }
  ],
  "expected_commits": {
    "core": "5350ec5",
    "ingestion": "7cec146"
  }
}
```

**Configuration Required:**
```env
# .env
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx
```

```php
// config/services.php
'github' => [
    'token' => env('GITHUB_TOKEN'),
],
```

---

## Tab: Dispatcher

### Header Controls (`header-controls.blade.php`)

Toggle for cooling down state.

### Total Stats (`total-stats.blade.php`)

Aggregate metrics across all hostnames.

### Hostname Stats (`hostname-stats.blade.php`)

Per-hostname breakdown of step processing.

---

## Tab: Artisan

### Artisan Commands (`artisan-commands.blade.php`)

List and execute artisan commands.

**Features:**
- Searchable command list
- Command execution with output display
- Grouped by namespace

---

## CSS Classes

Defined in `resources/css/app.css`:

| Class | Purpose |
|-------|---------|
| `.glass-effect` | Glassmorphism background with blur |
| `.skeleton` | Shimmer animation for loading states |
| `.tab-link` | Tab navigation styling |
| `.tab-link.active` | Active tab state |

## URL State

Tab changes update the URL query parameter (`/analytics?tab=health`) using `history.pushState()`. Browser refresh maintains the active tab.

## Turbo Frame Integration

Each tab content is loaded via Turbo Frame (`id="tab-content"`). Components handle:
- `turbo:frame-load`: Re-initialize when tab is loaded
- `turbo:before-frame-render`: Cleanup (clear intervals, reset state)

```javascript
// Re-initialize on Turbo frame load
document.addEventListener('turbo:frame-load', function(event) {
    if (event.target.id === 'tab-content') {
        setTimeout(function() {
            if (document.getElementById('component-id')) {
                Component.destroy();
                Component.init();
            }
        }, 50);
    }
});

// Clean up when navigating away
document.addEventListener('turbo:before-frame-render', function(event) {
    if (event.target.id === 'tab-content') {
        Component.destroy();
    }
});
```
