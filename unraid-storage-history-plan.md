# Unraid Storage History Plugin — Development Plan

## 1. Project overview

**Working name:** `Storage History`

**Target platform:** Unraid OS 7.x  
**Initial target/test system:** Unraid 7.3.2  
**Primary goal:** Add a native Unraid experience for viewing historical storage consumption over time.

The plugin should collect storage-capacity measurements periodically and display them as historical graphs directly within the Unraid WebGUI, preferably through a Dashboard widget plus a dedicated detailed page.

The plugin should be lightweight, self-contained, upgrade-safe, and avoid requiring Prometheus, InfluxDB, Grafana, or another external monitoring stack.

---

## 2. Desired functionality

### Dashboard widget

Add a native-looking Dashboard widget showing:

- Current array used space
- Current array free space
- Current array total capacity
- Historical storage graph
- Configurable time range
- Approximate growth rate

Example:

```text
┌──────────────────────────────────────────────┐
│ Storage History                         ⚙    │
│                                              │
│  12 TB ┤                                     │
│        │╲                                    │
│  10 TB ┤ ╲                                   │
│        │  ╲___                               │
│   8 TB ┤      ╲____                          │
│        │           ╲                         │
│   6 TB ┤            ╲___                     │
│        └──────────────────────────────       │
│         30d       14d       7d        now    │
│                                              │
│  Used       8.42 TB                          │
│  Free       4.18 TB                          │
│  Growth     +182 GB/month                    │
└──────────────────────────────────────────────┘
```

### Detailed page

Provide a dedicated page, for example:

`Main -> Tools -> Storage History`

It should support:

- 24 hours
- 7 days
- 30 days
- 90 days
- 1 year
- All time

The detailed page can expose more information than the Dashboard widget.

---

## 3. Metrics to collect

### Array

Collect:

- Total capacity
- Used capacity
- Free capacity

### Pools

For each pool:

- Pool name
- Total capacity
- Used capacity
- Free capacity

### Individual disks

Potentially collect:

- Disk name
- Total capacity
- Used capacity
- Free capacity

Individual-disk history should initially be optional because it can produce significantly more data.

### Derived metrics

Calculate:

- Storage consumed per day
- Storage consumed per week
- Storage consumed per month
- Change over selected period
- Estimated time until 90% capacity
- Estimated time until 95% capacity
- Estimated time until full

Forecasting should only be displayed when there is enough historical data and a meaningful positive growth trend.

---

## 4. Data collection

### Sampling interval

Default:

**1 hour**

Possible configuration:

- 15 minutes
- 30 minutes
- 1 hour
- 6 hours
- 12 hours
- 24 hours

One-hour sampling is sufficient for storage-capacity history and keeps the plugin extremely lightweight.

### Collector

The collector should run independently of the WebGUI.

Possible implementation:

```text
/usr/local/emhttp/plugins/storage-history/scripts/collector
```

The collector should obtain storage information from Unraid's existing storage information/API mechanisms where practical instead of relying solely on generic `df` output.

The implementation should be investigated against Unraid 7.3.2 before finalizing the data source.

---

## 5. Data storage

The plugin should not require an external database.

Suggested location:

```text
/boot/config/plugins/storage-history/
```

Suggested structure:

```text
/boot/config/plugins/storage-history/
├── storage-history.cfg
├── data/
│   └── history.json
└── ...
```

However, the final storage format should be chosen after testing.

### Requirements

The data store should:

- Survive reboots
- Survive plugin upgrades
- Be easy to back up
- Be easy to inspect manually
- Avoid excessive writes to the USB flash drive
- Support efficient graph queries

### Important consideration: Unraid flash drive writes

The collector should **not write to the USB flash drive every few minutes indefinitely**.

Potential approaches:

1. Store recent data in RAM and periodically persist it.
2. Use a persistent location outside `/boot`, if appropriate.
3. Batch writes.
4. Aggregate old data to reduce storage and write frequency.

The final approach should prioritize USB flash longevity.

---

## 6. Historical data retention

Recommended default:

**1 year**

Potential retention settings:

- 30 days
- 90 days
- 1 year
- 2 years
- 5 years
- Unlimited

Data can be downsampled as it gets older.

Suggested strategy:

### Recent data

Keep hourly samples for approximately 90 days.

### Older data

Aggregate to daily samples.

Example:

```text
0–90 days       hourly
90–365 days     daily
1–5 years       weekly/monthly as appropriate
```

This keeps long-term history tiny.

---

## 7. Graph requirements

The graph should be visually consistent with Unraid/Dynamix.

### Main graph

Primary metric:

**Used space over time**

Optionally allow switching between:

- Used
- Free
- Total

### Time range

Provide quick selectors:

```text
24h | 7d | 30d | 90d | 1y | All
```

### Tooltip

Hovering over a point should show:

```text
August 8, 2026 20:00

Used: 8.42 TB
Free: 4.18 TB
Total: 12.60 TB
```

### Units

Use sensible units automatically:

- GB
- TB
- PB

Use decimal or binary units consistently throughout the plugin.

The preferred unit convention should match Unraid's UI where possible.

---

## 8. Dashboard widget

The Dashboard widget should be:

- Compact
- Responsive
- Dark/light-theme compatible
- Consistent with native Unraid UI
- Configurable
- Low CPU usage

Possible widget configuration:

```text
Storage History
────────────────────────

Metric:
[ Used ▼ ]

Range:
[ 30 days ▼ ]

Show:
[x] Growth rate
[x] Forecast
[ ] Individual pools
```

The Dashboard should show the current values even when there is not enough historical data for a graph.

---

## 9. Settings page

Add a settings page under:

`Settings -> Storage History`

Possible settings:

### Collection

- Enable/disable collection
- Sampling interval

### Retention

- Retention period
- Enable historical downsampling

### Metrics

- Array
- Pools
- Individual disks

### Dashboard

- Enable Dashboard widget
- Default graph metric
- Default time range

### Forecast

- Enable forecast
- Threshold: 90%
- Threshold: 95%
- Full capacity forecast

---

## 10. Installation

The plugin should eventually be installable using an Unraid `.plg` file.

Development installation can initially be done manually.

Expected plugin structure:

```text
storage-history/
├── storage-history.plg
├── scripts/
│   ├── collector
│   └── ...
├── pages/
│   └── StorageHistory.page
├── include/
│   └── ...
├── assets/
│   ├── storage-history.js
│   └── storage-history.css
└── README.md
```

The exact structure should follow current Unraid 7.x plugin conventions rather than assuming an older plugin layout.

---

## 11. Upgrade and uninstall behavior

The plugin must not modify or overwrite core Unraid files.

### Upgrade

Plugin upgrades should preserve:

- Configuration
- Historical data

### Uninstall

Uninstall should remove:

- Plugin code
- Scheduled jobs
- WebGUI components

It should **not automatically delete historical data** without explicit confirmation.

Potential uninstall option:

```text
Remove historical data too?
[ ] Yes, permanently delete Storage History data
```

---

## 12. Scheduling

The collector needs a reliable scheduling mechanism.

Investigate the preferred Unraid 7.x mechanism for plugin background jobs.

Possible approaches:

- `cron`
- Unraid scheduler hooks
- Plugin-managed background process

Prefer the approach that is:

- Native
- Upgrade-safe
- Easy to stop/start
- Easy to remove during uninstall

The collector must avoid spawning duplicate processes after reboot or plugin reload.

---

## 13. Error handling

The collector should fail gracefully.

Examples:

- Array unavailable
- Pool unavailable
- Temporary emhttp/API error
- Invalid storage response
- Corrupt historical data
- Insufficient disk space
- Plugin disabled

Failures should be logged to the normal Unraid log mechanism.

The plugin should never interfere with array operation if its collector fails.

---

## 14. Performance goals

The plugin should be effectively negligible.

Target:

- Collector CPU usage: near zero when idle
- Collection operation: ideally <1 second
- WebGUI graph loading: <1 second for normal history ranges
- Minimal RAM usage
- Minimal disk writes
- No additional Docker containers
- No external services

---

## 15. Security

The plugin should:

- Use only local Unraid data
- Avoid opening network ports
- Avoid external telemetry
- Avoid remote services
- Validate all configuration input
- Avoid executing user-provided strings
- Use appropriate file permissions

No cloud account or external service should be required.

---

## 16. Compatibility

Initial target:

```text
Unraid 7.3.2
```

Compatibility target:

```text
Unraid 7.x
```

The implementation should avoid depending on private/internal APIs where possible.

Before release, test on at least:

- Unraid 7.3.x
- A system with array + cache/pool
- A system with multiple pools
- A system with no cache pool
- Array stopped
- Array starting
- Plugin disabled/enabled
- Reboot
- Plugin upgrade
- Plugin removal

---

## 17. Development phases

### Phase 1 — Research

Determine:

- Current Unraid 7.x plugin structure
- Current Dashboard widget APIs/patterns
- How Dynamix exposes storage information
- Best scheduling mechanism
- Best persistent-data location
- Existing JavaScript/chart libraries available in Unraid
- How current community plugins implement Dashboard widgets

Do not start by modifying Dynamix.

---

### Phase 2 — Minimal collector

Build a collector that produces one record:

```text
timestamp
array_total
array_used
array_free
```

Test manually.

Example:

```text
2026-08-08T20:00:00+02:00
total=12600000000000
used=8420000000000
free=4180000000000
```

Verify the numbers against the Unraid WebGUI.

---

### Phase 3 — Persistent history

Implement:

- Historical storage
- Retention
- Atomic writes
- Corruption handling
- Backup-safe storage
- Data migration/versioning

Test reboot persistence.

---

### Phase 4 — Automated collection

Add:

- Configurable interval
- Startup behavior
- Scheduled collection
- Logging
- Duplicate-process protection

Run for several days and inspect the resulting dataset.

---

### Phase 5 — Graph

Build the dedicated Storage History page.

Implement:

- Used/free graph
- Time range selector
- Tooltips
- Automatic units
- Empty/insufficient-data states

---

### Phase 6 — Dashboard widget

Add the compact Dashboard widget.

Test:

- Desktop
- Different Dashboard layouts
- Dark mode
- Light mode
- Different screen widths

---

### Phase 7 — Pools and disks

Add pool history.

Then consider individual disk history.

This should be optional so users don't collect unnecessary data.

---

### Phase 8 — Analytics

Add:

- Growth rate
- Period-over-period change
- Forecast
- 90% threshold
- 95% threshold
- Estimated full date

Forecasting should be conservative and clearly labeled as an estimate.

---

### Phase 9 — Packaging

Create:

- `.plg`
- README
- Changelog
- Versioning
- Installation instructions
- Upgrade instructions
- Uninstall instructions

---

### Phase 10 — Community Applications

Once stable:

- Create GitHub repository
- Create Community Applications-compatible repository entry
- Add screenshots
- Add documentation
- Publish initial release

---

## 18. Proposed GitHub repository

Suggested name:

```text
unraid-storage-history
```

Suggested structure:

```text
unraid-storage-history/
├── README.md
├── CHANGELOG.md
├── LICENSE
├── storage-history.plg
├── src/
│   ├── scripts/
│   ├── pages/
│   ├── include/
│   └── assets/
├── build/
└── docs/
```

The source layout can be changed to match Unraid community plugin conventions discovered during Phase 1.

---

## 19. Important design principles

### Do not modify core Unraid files

The plugin should remain self-contained.

### Do not require Docker

The main reason for building this as a native plugin is to avoid adding another monitoring stack.

### Do not depend on Grafana/Prometheus

Those remain excellent alternatives for comprehensive infrastructure monitoring, but they are unnecessary for this specific feature.

### Minimize flash writes

This is particularly important for Unraid's USB boot device.

### Keep the UI native

The user should feel like this is part of Unraid rather than a separate web application.

### Fail safely

A storage-history failure must never affect:

- Array operation
- Docker
- Shares
- Parity
- Pools
- Other Unraid services

---

## 20. Initial MVP

The first usable version should intentionally be small.

### MVP features

- [x] Native Unraid plugin
- [x] Unraid 7.x target
- [x] Array used/free/total collection
- [x] Hourly sampling
- [x] Persistent history
- [x] 30/90/365-day retention options
- [x] 24h/7d/30d/90d/1y graph ranges
- [x] Native-looking Storage History page
- [x] Dashboard widget
- [x] Current storage statistics
- [x] Growth rate

### Later features

- [ ] Pools
- [ ] Individual disks
- [ ] Forecasting
- [ ] 90%/95% thresholds
- [ ] Long-term downsampling
- [ ] Advanced widget configuration
- [ ] Community Applications release

---

## 21. First implementation task

Before writing the plugin itself, investigate the current Unraid 7.3.2 environment and answer these questions:

1. What exact API/data source does the Unraid WebGUI use for current array capacity?
2. How do current Unraid 7.x plugins add Dashboard widgets?
3. What scheduler mechanism should the plugin use?
4. Where should persistent history live to minimize `/boot` writes?
5. Which charting library is already available, if any?
6. What is the cleanest way to package the plugin for Unraid 7.x?
7. Can the plugin reuse Dynamix's existing storage/statistics data without modifying Dynamix?

Only after these are established should implementation begin.

---

## 22. Success criteria

The project is considered successful when, after installing the plugin:

1. Unraid continues operating normally.
2. A Storage History widget appears on the Dashboard.
3. The widget shows current array capacity.
4. Historical measurements accumulate automatically.
5. A graph shows used/free storage over time.
6. Data survives reboot and plugin upgrades.
7. Flash writes remain low.
8. No Docker container or external monitoring service is required.
9. The plugin can be completely removed without modifying core Unraid.
10. The plugin works reliably on Unraid 7.3.2 and remains compatible with the wider Unraid 7.x series.
