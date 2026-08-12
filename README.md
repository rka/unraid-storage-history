# Storage History

Storage History adds a compact array-capacity graph to the Unraid Dashboard. It records used, free, and total space over time without Docker or an external monitoring stack.

## Install

After the repository is pushed, install from Unraid’s **Plugins → Install Plugin** screen using:

```text
https://raw.githubusercontent.com/rka/unraid-storage-history/main/storage-history.plg
```

The Dashboard’s Content Manager lets you place the **Storage History** tile where you want it. Configuration is available under **Settings → Storage History**.

## What it records

- Array used, free, and total capacity, based on the same emhttp values used by Dynamix. On pool-only servers, it records the aggregate of mounted pools instead.
- An hourly sample by default, configurable from 15 minutes to daily.
- A 365-day history by default, stored at `/mnt/user/system/storage-history/history.json` so normal sampling does not write to the USB boot device.

The tile includes an adaptively scaled used-space graph, hover details, current figures, selectable time ranges, and average growth per day. Once a selected range contains at least seven days of data, positive growth also includes an estimated time remaining. Live read/write meters and collection freshness stay visible without requiring another page.

## Notes

The plugin does not modify Dynamix or expose any network service. It skips sampling when the array is unavailable. Plugin removal removes the runtime files and schedule but preserves your settings and history.
