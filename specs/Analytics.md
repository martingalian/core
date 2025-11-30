# Analytics Dashboard Architecture

## Overview

The Analytics dashboard (`/analytics`) is a Turbo-based architecture for real-time monitoring and system management. It uses lazy-loaded tabs with independent, self-refreshing components.

## Technology Stack

- **Turbo (Hotwire)**: Lazy-loading tab content via Turbo Frames
- **Vite**: Asset bundling (JS/CSS)
- **Tailwind CSS v4**: Styling
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
    │   └── ServersStatusController.php        # Returns server list + endpoints
    ├── Dispatcher/
    │   ├── TotalStatsController.php
    │   ├── HostnameStatsController.php
    │   └── WorkflowStatsController.php
    ├── Tables/
    │   └── TableCountsController.php
    └── Artisan/
        └── CommandsController.php
```

### Views

```
resources/views/analytics/
├── index.blade.php                            # Main layout with Turbo Frame
├── partials/
│   └── _header.blade.php                      # Shared header
└── tabs/
    ├── health/
    │   ├── index.blade.php                    # Tab container
    │   └── components/
    │       └── servers-status.blade.php       # Master component
    ├── dispatcher/
    │   ├── index.blade.php
    │   └── components/
    │       ├── total-stats.blade.php
    │       ├── hostname-stats.blade.php
    │       └── workflow-stats.blade.php
    ├── tables/
    │   ├── index.blade.php
    │   └── components/
    │       └── table-counts.blade.php
    ├── analytics/
    │   ├── index.blade.php
    │   └── components/
    │       └── ...
    └── artisan/
        ├── index.blade.php
        └── components/
            └── commands.blade.php
```

## Routes

```php
// Main layout
GET /analytics                                  → TabController@index

// Tab content (Turbo Frame)
GET /analytics/tab/{tab}                        → TabController@tab

// Component APIs
GET /analytics/api/health/servers-status        → ServersStatusController
GET /analytics/api/dispatcher/total-stats       → TotalStatsController
GET /analytics/api/dispatcher/hostname-stats    → HostnameStatsController
GET /analytics/api/dispatcher/workflow-stats    → WorkflowStatsController
GET /analytics/api/tables/counts                → TableCountsController
GET /analytics/api/artisan/commands             → CommandsController
```

## Component Implementation Pattern

### Master Component (Blade)

```html
<div id="servers-status" class="glass-effect rounded-lg p-4">
    <!-- Skeleton state -->
    <div class="skeleton-container">
        <div class="skeleton h-6 w-32 mb-4"></div>
        <div class="grid grid-cols-3 gap-4">
            <div class="skeleton h-24 rounded-lg"></div>
            <div class="skeleton h-24 rounded-lg"></div>
            <div class="skeleton h-24 rounded-lg"></div>
        </div>
    </div>

    <!-- Content container (hidden until data loads) -->
    <div class="content-container hidden">
        <h3 class="text-white font-bold mb-4">Servers Status</h3>
        <div class="tiles-container grid grid-cols-3 gap-4">
            <!-- Child tiles rendered here by JS -->
        </div>
    </div>
</div>

<script>
(function() {
    const component = document.getElementById('servers-status');
    const skeleton = component.querySelector('.skeleton-container');
    const content = component.querySelector('.content-container');
    const tilesContainer = component.querySelector('.tiles-container');

    // Fetch metadata
    fetch('/analytics/api/health/servers-status')
        .then(r => r.json())
        .then(data => {
            // Hide skeleton, show content
            skeleton.classList.add('hidden');
            content.classList.remove('hidden');

            // Render child tiles
            data.servers.forEach(server => {
                renderServerTile(tilesContainer, server);
            });
        });

    function renderServerTile(container, server) {
        // Create tile element
        // Tile manages its own data fetching and refresh
    }
})();
</script>
```

### Master Component API Response

```json
{
    "servers": [
        {
            "hostname": "api1",
            "endpoint": "https://api1.martingalian.com/alive",
            "refresh_interval": 5000
        },
        {
            "hostname": "api2",
            "endpoint": "https://api2.martingalian.com/alive",
            "refresh_interval": 5000
        }
    ]
}
```

### Child UI Tile Behavior

Each tile after initialization:
1. Immediately fetches its endpoint
2. Renders status (online/offline/error)
3. Sets up refresh interval
4. Continues refreshing independently

## Tabs

| Tab | Description | Master Components |
|-----|-------------|-------------------|
| Analytics | Charts and metrics | TBD |
| Tables | Database table counts | table-counts |
| Health | Server status monitoring | servers-status |
| Dispatcher | Step processing metrics | total-stats, hostname-stats, workflow-stats |
| Artisan | Run artisan commands | commands |

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
