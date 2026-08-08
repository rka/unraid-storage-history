<?php
declare(strict_types=1);

const SH_PLUGIN_DIR = '/usr/local/emhttp/plugins/storage-history';
const SH_CONFIG = '/boot/config/plugins/storage-history/storage-history.cfg';

function sh_config(): array {
    $defaults = [
        'enabled' => 'yes',
        'interval' => 'hourly',
        'retention_days' => '365',
        // The System share avoids periodic USB flash writes.  Collection waits
        // until the array is available rather than falling back to /boot.
        'data_path' => '/mnt/user/system/storage-history',
    ];
    $saved = is_readable(SH_CONFIG) ? (parse_ini_file(SH_CONFIG, false, INI_SCANNER_RAW) ?: []) : [];
    return array_merge($defaults, $saved);
}

function sh_data_file(array $config): string { return rtrim($config['data_path'], '/') . '/history.json'; }

function sh_empty_history(): array { return ['version' => 1, 'samples' => []]; }

function sh_read_history(array $config): array {
    $path = sh_data_file($config);
    if (!is_readable($path)) return sh_empty_history();
    $json = file_get_contents($path);
    $data = is_string($json) ? json_decode($json, true) : null;
    return is_array($data) && ($data['version'] ?? null) === 1 && is_array($data['samples'] ?? null)
        ? $data : sh_empty_history();
}

function sh_write_history(array $config, array $history): bool {
    $directory = dirname(sh_data_file($config));
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) return false;
    $path = sh_data_file($config);
    $temp = $path . '.tmp.' . getmypid();
    $encoded = json_encode($history, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === false || file_put_contents($temp, $encoded . "\n", LOCK_EX) === false) return false;
    return rename($temp, $path);
}

function sh_number(array $data, string $key): int { return isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : 0; }

/** Read the same emhttp state file the Dynamix Dashboard uses. Values are KiB. */
function sh_current_sample(): ?array {
    $disks = @parse_ini_file('/usr/local/emhttp/state/disks.ini', true, INI_SCANNER_RAW);
    if (!is_array($disks)) return null;
    $arrayTotal = 0; $arrayFree = 0; $arraySeen = 0;
    $poolTotal = 0; $poolFree = 0; $poolNames = [];
    foreach ($disks as $name => $disk) {
        if (($disk['type'] ?? '') !== 'Data' || !isset($disk['fsSize'], $disk['fsFree'])) continue;
        if (!is_numeric($disk['fsSize']) || !is_numeric($disk['fsFree'])) continue;
        $arrayTotal += sh_number($disk, 'fsSize') * 1024;
        $arrayFree += sh_number($disk, 'fsFree') * 1024;
        $arraySeen++;
    }
    foreach ($disks as $name => $disk) {
        if (($disk['type'] ?? '') !== 'Cache' || !isset($disk['fsSize'], $disk['fsFree'])) continue;
        if (!is_numeric($disk['fsSize']) || !is_numeric($disk['fsFree'])) continue;
        $poolTotal += sh_number($disk, 'fsSize') * 1024;
        $poolFree += sh_number($disk, 'fsFree') * 1024;
        $poolNames[] = $name;
    }
    if ($arraySeen > 0 && $arrayTotal > 0) {
        $total = $arrayTotal; $free = $arrayFree; $source = 'array';
    } elseif ($poolTotal > 0) {
        // Pool-only servers are a supported Unraid configuration.  Aggregate
        // pool headers, not member disks, to avoid counting a pool twice.
        $total = $poolTotal; $free = $poolFree; $source = count($poolNames) === 1 ? 'pool:' . $poolNames[0] : 'pools';
    } else return null;
    return ['timestamp' => time(), 'total' => $total, 'used' => max(0, $total - $free), 'free' => $free, 'source' => $source];
}

function sh_collect(): int {
    $config = sh_config();
    if (($config['enabled'] ?? 'yes') !== 'yes') return 0;
    $sample = sh_current_sample();
    if ($sample === null) return 0;
    $history = sh_read_history($config);
    $history['samples'][] = $sample;
    $cutoff = time() - max(30, (int)$config['retention_days']) * 86400;
    $history['samples'] = array_values(array_filter($history['samples'], fn($row) => is_array($row) && ($row['timestamp'] ?? 0) >= $cutoff));
    // Avoid duplicate records if cron is reloaded around an hour boundary.
    $history['samples'] = array_values(array_reduce($history['samples'], function(array $carry, array $row): array {
        $last = end($carry);
        if (is_array($last) && abs(($last['timestamp'] ?? 0) - ($row['timestamp'] ?? 0)) < 60) { $carry[key($carry)] = $row; return $carry; }
        $carry[] = $row; return $carry;
    }, []));
    return sh_write_history($config, $history) ? 0 : 1;
}

function sh_payload(string $range): array {
    $config = sh_config();
    $history = sh_read_history($config);
    $seconds = ['24h'=>86400, '7d'=>604800, '30d'=>2592000, '90d'=>7776000, '1y'=>31536000, 'all'=>PHP_INT_MAX];
    $range = array_key_exists($range, $seconds) ? $range : '30d';
    $cutoff = $seconds[$range] === PHP_INT_MAX ? 0 : time() - $seconds[$range];
    $samples = array_values(array_filter($history['samples'], fn($row) => is_array($row) && ($row['timestamp'] ?? 0) >= $cutoff));
    $current = sh_current_sample();
    $growth = null;
    if (count($samples) >= 2) {
        $first = $samples[0]; $last = $samples[count($samples)-1]; $duration = ($last['timestamp'] ?? 0) - ($first['timestamp'] ?? 0);
        if ($duration >= 86400) $growth = (($last['used'] ?? 0) - ($first['used'] ?? 0)) * 86400 / $duration;
    }
    return ['current' => $current, 'samples' => $samples, 'growth_per_day' => $growth, 'range' => $range, 'io' => sh_io_rates()];
}

/** Return aggregate physical-disk throughput. Baselines are held in RAM only. */
function sh_io_rates(): array {
    $read = 0; $write = 0;
    foreach (@file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 10 || !preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', $parts[2])) continue;
        $read += (int)$parts[5] * 512;
        $write += (int)$parts[9] * 512;
    }
    $now = microtime(true); $baseline = '/var/run/storage-history-io.json';
    $previous = is_readable($baseline) ? json_decode((string)file_get_contents($baseline), true) : null;
    @file_put_contents($baseline, json_encode(['time' => $now, 'read' => $read, 'write' => $write]), LOCK_EX);
    $seconds = is_array($previous) ? max(0.1, $now - (float)($previous['time'] ?? $now)) : 1;
    return [
        'read_per_second' => is_array($previous) ? max(0, ($read - (int)($previous['read'] ?? $read)) / $seconds) : 0,
        'write_per_second' => is_array($previous) ? max(0, ($write - (int)($previous['write'] ?? $write)) / $seconds) : 0,
    ];
}

if (PHP_SAPI === 'cli') exit(sh_collect());
