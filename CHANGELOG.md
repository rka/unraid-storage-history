# Changelog

## 0.3.1 — 2026-08-12

- Restrict the preserved legacy migration backup to owner-only access.

## 0.3.0 — 2026-08-12

- Store capacity samples in append-only monthly segments and migrate legacy history without deleting its backup.
- Bound, validate, and downsample history reads while preserving chart extrema.
- Split live I/O from history polling, pause background-tab work, and avoid rebuilding the tile every five seconds.
- Harden settings paths and atomic writes, serialize I/O baselines, restrict file permissions, and use immutable release assets.
- Add automated syntax, behavior, and release-manifest validation.

## 0.2.1 — 2026-08-12

- Correct normalized source checksums for reliable UI and CLI updates.

## 0.2.0 — 2026-08-12

- Use adaptive chart scaling with hover details for date, used, free, and sample-to-sample change.
- Add Dashboard range controls, a utilization strip, and credible remaining-capacity estimates.
- Improve live I/O idle states, responsive layouts, theme integration, and collection freshness indicators.

## 0.1.7 — 2026-08-08

- Refresh the Dashboard capacity summary and live I/O presentation.

## 0.1.6 — 2026-08-08

- Balance the Dashboard live I/O meters across the tile width.

## 0.1.5 — 2026-08-08

- Use a compact btop-style dot meter for live disk I/O.
- Align chart and telemetry styling with the Dashboard’s restrained native look.

## 0.1.4 — 2026-08-08

- Add a dedicated Storage History settings section.
- Improve the Dashboard chart and add live aggregate disk I/O indicators.

## 0.1.3 — 2026-08-08

- Add file checksums so plugin updates replace changed files.

## 0.1.2 — 2026-08-08

- Record aggregate pool capacity on pool-only servers.

## 0.1.1 — 2026-08-08

- Use the standard Unraid plugin manifest header.

## 0.1.0 — 2026-08-08

- Native Unraid Settings page and Dashboard tile.
- Array capacity collector using emhttp `disks.ini` state.
- JSON history, configurable cadence/retention, atomic writes, and growth display.
