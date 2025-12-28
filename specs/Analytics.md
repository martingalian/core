# Analytics Dashboard Architecture

## Overview

The Analytics dashboard (`/analytics`) is a Turbo-based architecture for real-time monitoring and system management. It uses lazy-loaded tabs with independent, self-refreshing components.

---

## Technology Stack

| Technology | Purpose |
|------------|---------|
| Turbo (Hotwire) | Lazy-loading tab content via Turbo Frames |
| Vite | Asset bundling (JS/CSS) |
| Tailwind CSS v4 | Styling |
| Chart.js | Timeline charts |
| Vanilla JS | Component data fetching and rendering |

---

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
| Tab | No | No | Container for master components |
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

---

## Routes

### Main Layout

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /analytics` | TabController@index | Main layout |
| `GET /analytics/tab/{tab}` | TabController@tab | Tab content (Turbo Frame) |

### Component APIs

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /analytics/api/analytics/completed-steps-timeline` | CompletedStepsTimelineController | Timeline chart data |
| `GET /analytics/api/health/servers-status` | ServersStatusController | Server list + endpoints |
| `GET /analytics/api/dispatcher/header` | HeaderControlsController | Cooling toggle controls |
| `POST /analytics/api/dispatcher/toggle-cooling` | HeaderControlsController@toggle | Toggle cooldown |
| `GET /analytics/api/dispatcher/total-stats` | TotalStatsController | Aggregate step metrics |
| `GET /analytics/api/dispatcher/hostname-stats` | HostnameStatsController | Per-hostname breakdown |
| `GET /analytics/api/tables/counts` | TableCountsController | Table counts |
| `GET /analytics/api/artisan/commands` | ArtisanCommandsController | Available commands |
| `POST /analytics/api/artisan/run` | ArtisanCommandsController@run | Execute command |

---

## Tabs

| Tab | Description | Components |
|-----|-------------|------------|
| Analytics | Completed steps timeline chart | completed-steps-timeline |
| Tables | Database table counts by letter | table-counts |
| Health | Server status with commit comparison | servers-status |
| Dispatcher | Step processing metrics | header-controls, total-stats, hostname-stats |
| Artisan | Run artisan commands | artisan-commands |

---

## Tab: Analytics

### Completed Steps Timeline

**Purpose**: Chart.js bar chart showing completed steps over last 2 hours in 5-minute intervals.

**Features**:
| Feature | Description |
|---------|-------------|
| Buckets | 24 buckets (5-minute intervals over 2 hours) |
| Highlighting | Current bucket cyan, past buckets pink |
| Caching | Completed buckets cached 2 hours, current bucket always fresh |
| Transitions | Smooth bar height transitions (400ms) |
| Auto-refresh | Every 10 seconds |

**Caching Strategy**:
- Completed buckets (not current): cached in Redis for 2 hours
- Current bucket: always queried fresh from DB
- Cache key format: `timeline:bucket:YYYY-MM-DD HH:mm:00`

---

## Tab: Tables

### Table Counts

**Purpose**: Display all database tables grouped by first letter with collapsible sections.

**Features**:
| Feature | Description |
|---------|-------------|
| MySQL stats | Database size, connections, threads, uptime, queries/sec |
| Grouping | Tables grouped alphabetically by first letter |
| Collapsible | Click to expand/collapse sections |
| Smart refresh | Only expanded sections refresh (every 5 seconds) |
| Caching | Main data cached 30 seconds |

---

## Tab: Health

### Servers Status

**Purpose**: Display real-time health status for all apiable servers.

**Features**:
| Feature | Description |
|---------|-------------|
| Gauges | CPU/MEM/DISK circular progress indicators |
| Status badges | Online (green), Maintenance (yellow), Error (orange), Offline (red) |
| Supervisor | Process status (running/total, fatal count) |
| Cron status | Active/inactive indicator |
| Git comparison | Compare server commit with GitHub latest |
| Auto-refresh | Every 10 seconds per tile |

**GitHub Commit Comparison**:
- Requires `GITHUB_TOKEN` environment variable with Contents: Read permission
- Results cached 60 seconds to avoid rate limiting
- Shows "(latest: abc1234)" next to outdated commits
- Green if matches GitHub, Red if outdated

---

## Tab: Dispatcher

### Header Controls

**Features**:
| Feature | Description |
|---------|-------------|
| Restart Semaphore | Green when safe to restart Horizon, red otherwise |
| Cooldown Toggle | Pauses cron jobs from creating new steps |
| Queue Size Badge | Total unprocessed steps |
| Cooldown Banner | Shows when cooldown active |

### Total Stats

**Features**:
| Feature | Description |
|---------|-------------|
| State Grid | 8-column display: PEND, DISP, RUN, THRT, DONE, FAIL, STOP, SKIP |
| Child Counts | Non-parent steps count (child_block_uuid IS NULL) |
| Volume Stats | Steps created in last 1h, 4h, 24h |
| Oldest Unprocessed | Age of oldest unprocessed step |
| Groups Section | Collapsible breakdown by step `group` |
| Classes Section | Collapsible breakdown by step `class` |
| Auto-refresh | Every 5 seconds |

### Hostname Stats

**Features**:
| Feature | Description |
|---------|-------------|
| Server Tiles | One tile per apiable server |
| State Grid | Same 8 states as Total Stats |
| Child Counts | Per-state child counts |
| Auto-refresh | Every 3 seconds per tile |

---

## Tab: Artisan

### Artisan Commands

**Purpose**: Execute whitelisted artisan commands from web UI.

**Features**:
| Feature | Description |
|---------|-------------|
| Card display | Command description |
| Options | Toggles/inputs based on command definition |
| Real-time output | Display after execution |
| Whitelisting | Only approved commands available |

**Whitelisted Commands**:
| Command | Purpose |
|---------|---------|
| `cronjobs:refresh-core-data` | Refresh symbol discovery, eligibility |
| `cronjobs:conclude-symbols-direction` | Trigger direction conclusion |
| `cronjobs:create-positions` | Create trading positions |
| `taapi:store-candles` | Store candle data |

---

## Styling

### CSS Classes

| Class | Purpose |
|-------|---------|
| `.glass-effect` | Glassmorphism background with blur |
| `.skeleton` | Shimmer animation for loading states |
| `.tab-link` | Tab navigation styling |
| `.tab-link.active` | Active tab state |

---

## URL State

Tab changes update URL query parameter (`/analytics?tab=health`) using `history.pushState()`. Browser refresh maintains active tab.

---

## Turbo Frame Integration

| Event | Action |
|-------|--------|
| `turbo:frame-load` | Re-initialize component when tab loads |
| `turbo:before-frame-render` | Cleanup (clear intervals, reset state) |

---

## File Structure

### Controllers

```
app/Controllers/Analytics/
├── TabController.php
└── Components/
    ├── Health/ServersStatusController.php
    ├── Analytics/CompletedStepsTimelineController.php
    ├── Dispatcher/
    │   ├── HeaderControlsController.php
    │   ├── TotalStatsController.php
    │   └── HostnameStatsController.php
    ├── Tables/TableCountsController.php
    └── Artisan/ArtisanCommandsController.php
```

### Views

```
resources/views/analytics/
├── index.blade.php
├── partials/_header.blade.php
└── tabs/
    ├── analytics/
    ├── tables/
    ├── health/
    ├── dispatcher/
    └── artisan/
```

---

## Related Systems

- **StepDispatcher**: Provides step metrics
- **Server**: Worker server registry
- **Horizon**: Queue monitoring
