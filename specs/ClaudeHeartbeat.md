# Claude Heartbeat Service

## Overview

Systemd service that sends periodic heartbeats to Claude Code every 5 hours. Keeps the rate limit window fresh and ensures Claude is warmed up for coding sessions.

---

## Purpose

**Problem**: Claude Code has a 5-hour rolling rate limit window. Heavy usage can exhaust limits, causing slowdowns or blocks.

**Solution**: Automated heartbeats every 5 hours maintain a perpetually fresh rate limit window and keep Claude responsive.

---

## How It Works

1. Service starts and immediately sends a heartbeat (`claude -p "hello"`)
2. Sleeps for 5 hours
3. Sends another heartbeat
4. Repeats indefinitely

---

## File Locations

| File | Path | Purpose |
|------|------|---------|
| Script | `~/.claude/scripts/claude-heartbeat.sh` | Heartbeat logic |
| Service | `~/.config/systemd/user/claude-heartbeat.service` | systemd unit |

---

## Script

```bash
#!/usr/bin/env bash

# Interval between heartbeats (5 hours in seconds)
HEARTBEAT_INTERVAL=$((5 * 60 * 60))

while true; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sending heartbeat..."

    if claude -p "hello" >/dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ Claude responded"
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✗ Claude failed"
    fi

    sleep "$HEARTBEAT_INTERVAL"
done
```

---

## Service Configuration

```ini
[Unit]
Description=Claude Code Heartbeat Service
After=default.target

[Service]
Type=simple
ExecStart=/home/bruno/.claude/scripts/claude-heartbeat.sh
Restart=always
RestartSec=10
Environment="PATH=/home/bruno/.nvm/versions/node/v20.19.4/bin:/usr/local/bin:/usr/bin:/bin"

[Install]
WantedBy=default.target
```

---

## Management Commands

| Action | Command |
|--------|---------|
| Check status | `systemctl --user status claude-heartbeat` |
| View logs | `journalctl --user -u claude-heartbeat -f` |
| Stop | `systemctl --user stop claude-heartbeat` |
| Start | `systemctl --user start claude-heartbeat` |
| Restart | `systemctl --user restart claude-heartbeat` |
| Disable | `systemctl --user disable claude-heartbeat` |

---

## Configuration

### Adjusting Interval

Edit the script and change `HEARTBEAT_INTERVAL`:

```bash
# 4 hours
HEARTBEAT_INTERVAL=$((4 * 60 * 60))

# 6 hours
HEARTBEAT_INTERVAL=$((6 * 60 * 60))
```

Then restart: `systemctl --user restart claude-heartbeat`

### Enabling Lingering

For the service to run even when not logged in:

```bash
sudo loginctl enable-linger bruno
```

---

## Subscription Considerations

| Plan | Rate Limits | Heartbeat Value |
|------|-------------|-----------------|
| Pro | Standard | High - prevents hitting limits |
| Max 5x | 5x Pro | Medium - less likely to hit limits |
| Max 20x | 20x Pro | Low - mainly for warm-up benefit |

---

## Troubleshooting

### Service Won't Start

1. Check claude CLI is accessible:
   ```bash
   which claude
   ```

2. Verify PATH in service file includes claude's location

3. Check logs for errors:
   ```bash
   journalctl --user -u claude-heartbeat --no-pager
   ```

### Claude Not Responding

1. Check authentication:
   ```bash
   claude -p "test"
   ```

2. Verify network connectivity

3. Check if rate limited (wait and retry)

---

## Credits

Adapted from: https://x.com/elvissun/status/2008328671061491891

---

## Related Systems

- **Claude Code**: The CLI tool being kept warm
- **systemd**: Service manager handling lifecycle
- **nvm**: Node version manager (claude installed via npm)
