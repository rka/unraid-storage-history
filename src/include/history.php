<?php
declare(strict_types=1);

const SH_PLUGIN_DIR = '/usr/local/emhttp/plugins/storage-history';
const SH_CONFIG = '/boot/config/plugins/storage-history/storage-history.cfg';
const SH_MAX_LEGACY_BYTES = 67108864;
const SH_MAX_SEGMENTS = 72;
const SH_MAX_SAMPLES_PER_SEGMENT = 4000;
const SH_MAX_RESPONSE_SAMPLES = 720;

function sh_config(): array {
    $defaults = [
        'enabled' => 'yes',
        'interval' => 'hourly',
        'retention_days' => '365',
        'data_path' => '/mnt/user/system/storage-history',
    ];
    $saved = is_readable(SH_CONFIG) ? (parse_ini_file(SH_CONFIG, false, INI_SCANNER_RAW) ?: []) : [];
    return array_merge($defaults, $saved);
}

function sh_data_root(array $config): string { return rtrim((string)$config['data_path'], '/'); }
function sh_data_file(array $config): string { return sh_data_root($config) . '/history.json'; }
function sh_history_dir(array $config): string { return sh_data_root($config) . '/history.d'; }

function sh_valid_data_path(string $path): bool {
    if ($path === '' || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\="\'`;#]/', $path)) return false;
    if (!str_starts_with($path, '/mnt/') || str_ends_with($path, '/')) return false;
    $parts = explode('/', $path);
    foreach (array_slice($parts, 1) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
    $ancestor = $path;
    while (!file_exists($ancestor) && $ancestor !== '/mnt') $ancestor = dirname($ancestor);
    $resolved = realpath($ancestor);
    return $resolved === false || $resolved === '/mnt' || str_starts_with($resolved, '/mnt/');
}

function sh_write_config(array $values): bool {
    $path = (string)($values['data_path'] ?? '');
    if (!sh_valid_data_path($path)) return false;
    $directory = dirname(SH_CONFIG);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return false;
    $temp = tempnam($directory, '.storage-history.');
    if ($temp === false) return false;
    @chmod($temp, 0600);
    $contents = 'enabled=' . (($values['enabled'] ?? '') === 'yes' ? 'yes' : 'no') . "\n"
        . 'interval=' . (string)$values['interval'] . "\n"
        . 'retention_days=' . (int)$values['retention_days'] . "\n"
        . 'data_path=' . $path . "\n";
    $handle = @fopen($temp, 'wb');
    if ($handle === false) { @unlink($temp); return false; }
    $ok = flock($handle, LOCK_EX) && fwrite($handle, $contents) === strlen($contents) && fflush($handle);
    flock($handle, LOCK_UN); fclose($handle);
    if (!$ok || !@rename($temp, SH_CONFIG)) { @unlink($temp); return false; }
    @chmod(SH_CONFIG, 0600);
    return true;
}

function sh_validate_sample(mixed $row): ?array {
    if (!is_array($row) || !isset($row['timestamp'], $row['total'], $row['used'], $row['free'])) return null;
    foreach (['timestamp', 'total', 'used', 'free'] as $key) if (!is_numeric($row[$key])) return null;
    $timestamp = (int)$row['timestamp']; $total = (int)$row['total']; $used = (int)$row['used']; $free = (int)$row['free'];
    if ($timestamp < 946684800 || $timestamp > time() + 86400 || $total <= 0 || $used < 0 || $free < 0 || $used > $total || $free > $total) return null;
    $source = isset($row['source']) && is_string($row['source']) ? substr($row['source'], 0, 80) : 'unknown';
    if (!preg_match('/^[A-Za-z0-9:_-]+$/', $source)) $source = 'unknown';
    return ['timestamp' => $timestamp, 'total' => $total, 'used' => $used, 'free' => $free, 'source' => $source];
}

function sh_segment_name(int $timestamp): string { return gmdate('Y-m', $timestamp) . '.jsonl'; }

function sh_segment_files(array $config): array {
    $directory = sh_history_dir($config);
    if (!is_dir($directory)) return [];
    $files = [];
    foreach (scandir($directory) ?: [] as $name) {
        if (preg_match('/^\d{4}-\d{2}\.jsonl$/', $name)) $files[] = $directory . '/' . $name;
    }
    sort($files, SORT_STRING);
    return array_slice($files, -SH_MAX_SEGMENTS);
}

function sh_atomic_lines(string $path, array $samples): bool {
    $directory = dirname($path);
    $temp = tempnam($directory, '.segment.');
    if ($temp === false) return false;
    @chmod($temp, 0600);
    $handle = @fopen($temp, 'wb');
    if ($handle === false) { @unlink($temp); return false; }
    $ok = flock($handle, LOCK_EX);
    foreach ($samples as $sample) {
        $line = json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($line === false || fwrite($handle, $line . "\n") === false) { $ok = false; break; }
    }
    $ok = $ok && fflush($handle); flock($handle, LOCK_UN); fclose($handle);
    if (!$ok || !@rename($temp, $path)) { @unlink($temp); return false; }
    @chmod($path, 0600);
    return true;
}

function sh_migrate_legacy(array $config): bool {
    $target = sh_history_dir($config);
    if (is_dir($target)) return true;
    $root = sh_data_root($config);
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) return false;
    @chmod($root, 0700);
    $lock = @fopen('/var/run/storage-history-migrate.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) { if (is_resource($lock)) fclose($lock); return false; }
    if (is_dir($target)) { flock($lock, LOCK_UN); fclose($lock); return true; }
    $legacy = sh_data_file($config); $groups = [];
    if (is_readable($legacy)) {
        $size = filesize($legacy);
        if ($size === false || $size > SH_MAX_LEGACY_BYTES) { flock($lock, LOCK_UN); fclose($lock); return false; }
        $decoded = json_decode((string)file_get_contents($legacy), true);
        if (!is_array($decoded) || !is_array($decoded['samples'] ?? null)) { flock($lock, LOCK_UN); fclose($lock); return false; }
        foreach (is_array($decoded['samples'] ?? null) ? $decoded['samples'] : [] as $row) {
            $sample = sh_validate_sample($row);
            if ($sample !== null) $groups[sh_segment_name($sample['timestamp'])][] = $sample;
        }
    }
    $temporary = $root . '/history.d.migrate.' . bin2hex(random_bytes(6));
    if (!mkdir($temporary, 0700)) { flock($lock, LOCK_UN); fclose($lock); return false; }
    $ok = true;
    foreach ($groups as $name => $samples) if (!sh_atomic_lines($temporary . '/' . $name, array_slice($samples, -SH_MAX_SAMPLES_PER_SEGMENT))) { $ok = false; break; }
    if ($ok) $ok = @rename($temporary, $target);
    if (!$ok) { foreach (glob($temporary . '/*') ?: [] as $file) @unlink($file); @rmdir($temporary); }
    if ($ok && is_file($legacy) && !is_file($legacy . '.migrated')) @rename($legacy, $legacy . '.migrated');
    flock($lock, LOCK_UN); fclose($lock);
    return $ok;
}

function sh_read_segment(string $path, int $cutoff = 0): array {
    $samples = []; $handle = @fopen($path, 'rb');
    if ($handle === false) return [];
    while (!feof($handle) && count($samples) < SH_MAX_SAMPLES_PER_SEGMENT) {
        $line = fgets($handle, 8192);
        if ($line === false) break;
        $sample = sh_validate_sample(json_decode($line, true));
        if ($sample !== null && $sample['timestamp'] >= $cutoff) $samples[] = $sample;
    }
    fclose($handle);
    usort($samples, fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return $samples;
}

function sh_read_samples(array $config, int $cutoff = 0): array {
    if (!sh_migrate_legacy($config)) return [];
    $samples = []; $month = $cutoff > 0 ? gmdate('Y-m', $cutoff) : '';
    foreach (sh_segment_files($config) as $file) {
        if ($month !== '' && basename($file, '.jsonl') < $month) continue;
        array_push($samples, ...sh_read_segment($file, $cutoff));
    }
    return $samples;
}

function sh_history_bounds(array $config): array {
    $files = sh_segment_files($config);
    if (!$files) return [0, 0];
    $first = sh_read_segment($files[0]); $last = sh_read_segment($files[count($files) - 1]);
    return [$first ? $first[0]['timestamp'] : 0, $last ? $last[count($last) - 1]['timestamp'] : 0];
}

function sh_append_sample(array $config, array $sample): bool {
    if (!sh_migrate_legacy($config)) return false;
    $path = sh_history_dir($config) . '/' . sh_segment_name($sample['timestamp']);
    $handle = @fopen($path, 'a+');
    if ($handle === false || !flock($handle, LOCK_EX)) { if (is_resource($handle)) fclose($handle); return false; }
    @chmod($path, 0600);
    rewind($handle); $last = null; $count = 0;
    while (!feof($handle) && $count <= SH_MAX_SAMPLES_PER_SEGMENT) {
        $line = fgets($handle, 8192); if ($line === false) break;
        $valid = sh_validate_sample(json_decode($line, true)); if ($valid !== null) { $last = $valid; $count++; }
    }
    $ok = true;
    if ($last === null || abs($last['timestamp'] - $sample['timestamp']) >= 60) {
        if ($count >= SH_MAX_SAMPLES_PER_SEGMENT) $ok = false;
        else { fseek($handle, 0, SEEK_END); $line = json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); $ok = $line !== false && fwrite($handle, $line . "\n") !== false && fflush($handle); }
    }
    flock($handle, LOCK_UN); fclose($handle);
    return $ok;
}

function sh_compact(array $config): void {
    $marker = '/var/run/storage-history-compact';
    if (is_file($marker) && time() - (int)@filemtime($marker) < 86400) return;
    @touch($marker);
    $cutoff = time() - max(30, min(1825, (int)$config['retention_days'])) * 86400;
    $boundary = gmdate('Y-m', $cutoff);
    foreach (sh_segment_files($config) as $file) {
        $month = basename($file, '.jsonl');
        if ($month < $boundary) @unlink($file);
        elseif ($month === $boundary) sh_atomic_lines($file, sh_read_segment($file, $cutoff));
    }
}

function sh_downsample(array $samples, int $limit = SH_MAX_RESPONSE_SAMPLES): array {
    $count = count($samples);
    if ($count <= $limit || $limit < 4) return $samples;
    $result = [$samples[0]]; $interior = $count - 2; $buckets = max(1, intdiv($limit - 2, 2));
    for ($bucket = 0; $bucket < $buckets; $bucket++) {
        $start = 1 + (int)floor($bucket * $interior / $buckets);
        $end = 1 + (int)floor(($bucket + 1) * $interior / $buckets);
        if ($end <= $start) continue;
        $minIndex = $start; $maxIndex = $start;
        for ($index = $start + 1; $index < $end; $index++) {
            if ($samples[$index]['used'] < $samples[$minIndex]['used']) $minIndex = $index;
            if ($samples[$index]['used'] > $samples[$maxIndex]['used']) $maxIndex = $index;
        }
        foreach (array_unique([$minIndex, $maxIndex]) as $index) $result[] = $samples[$index];
    }
    $result[] = $samples[$count - 1];
    usort($result, fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    return array_slice($result, 0, $limit);
}

function sh_number(array $data, string $key): int { return isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : 0; }

function sh_current_sample(): ?array {
    $disks = @parse_ini_file('/usr/local/emhttp/state/disks.ini', true, INI_SCANNER_RAW);
    if (!is_array($disks)) return null;
    $arrayTotal = 0; $arrayFree = 0; $arraySeen = 0; $poolTotal = 0; $poolFree = 0; $poolNames = [];
    foreach ($disks as $disk) {
        if (($disk['type'] ?? '') !== 'Data' || !isset($disk['fsSize'], $disk['fsFree']) || !is_numeric($disk['fsSize']) || !is_numeric($disk['fsFree'])) continue;
        $arrayTotal += sh_number($disk, 'fsSize') * 1024; $arrayFree += sh_number($disk, 'fsFree') * 1024; $arraySeen++;
    }
    foreach ($disks as $name => $disk) {
        if (($disk['type'] ?? '') !== 'Cache' || !isset($disk['fsSize'], $disk['fsFree']) || !is_numeric($disk['fsSize']) || !is_numeric($disk['fsFree'])) continue;
        $poolTotal += sh_number($disk, 'fsSize') * 1024; $poolFree += sh_number($disk, 'fsFree') * 1024; $poolNames[] = $name;
    }
    if ($arraySeen > 0 && $arrayTotal > 0) { $total = $arrayTotal; $free = $arrayFree; $source = 'array'; }
    elseif ($poolTotal > 0) { $total = $poolTotal; $free = $poolFree; $source = count($poolNames) === 1 ? 'pool:' . $poolNames[0] : 'pools'; }
    else return null;
    return ['timestamp' => time(), 'total' => $total, 'used' => max(0, $total - $free), 'free' => $free, 'source' => $source];
}

function sh_collect(): int {
    $config = sh_config();
    if (($config['enabled'] ?? 'yes') !== 'yes') return 0;
    $sample = sh_current_sample();
    if ($sample === null || !sh_append_sample($config, $sample)) return $sample === null ? 0 : 1;
    sh_compact($config);
    return 0;
}

function sh_history_payload(string $range): array {
    $config = sh_config();
    $seconds = ['24h'=>86400, '7d'=>604800, '30d'=>2592000, '90d'=>7776000, '1y'=>31536000, 'all'=>PHP_INT_MAX];
    $range = array_key_exists($range, $seconds) ? $range : '30d';
    $cutoff = $seconds[$range] === PHP_INT_MAX ? 0 : time() - $seconds[$range];
    $samples = sh_read_samples($config, $cutoff); $current = sh_current_sample(); $growth = null;
    $rangeSpan = count($samples) >= 2 ? max(0, $samples[count($samples) - 1]['timestamp'] - $samples[0]['timestamp']) : 0;
    if ($rangeSpan >= 86400) $growth = ($samples[count($samples) - 1]['used'] - $samples[0]['used']) * 86400 / $rangeSpan;
    [$firstTimestamp, $lastTimestamp] = sh_history_bounds($config);
    $intervalSeconds = ['15min'=>900, '30min'=>1800, 'hourly'=>3600, '6hourly'=>21600, '12hourly'=>43200, 'daily'=>86400][$config['interval'] ?? 'hourly'] ?? 3600;
    $status = ($config['enabled'] ?? 'yes') !== 'yes' ? 'paused' : ($firstTimestamp === 0 || $firstTimestamp === $lastTimestamp ? 'collecting' : 'ready');
    if ($status === 'ready' && time() - $lastTimestamp > max(10800, (int)($intervalSeconds * 2.5))) $status = 'stale';
    return ['current'=>$current, 'samples'=>sh_downsample($samples), 'growth_per_day'=>$growth, 'range'=>$range, 'history'=>[
        'status'=>$status, 'sample_count'=>count($samples), 'span_seconds'=>max(0, $lastTimestamp - $firstTimestamp),
        'range_span_seconds'=>$rangeSpan, 'last_sample_at'=>$lastTimestamp ?: null,
    ]];
}

function sh_payload(string $range): array { return sh_history_payload($range) + ['io' => sh_io_rates()]; }

function sh_io_rates(): array {
    $read = 0; $write = 0;
    foreach (@file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 10 || !preg_match('/^(sd[a-z]+|hd[a-z]+|vd[a-z]+|nvme\d+n\d+)$/', $parts[2])) continue;
        $read += (int)$parts[5] * 512; $write += (int)$parts[9] * 512;
    }
    $now = microtime(true); $baseline = '/var/run/storage-history-io.json'; $previous = null;
    $handle = @fopen($baseline, 'c+');
    if ($handle !== false && flock($handle, LOCK_EX)) {
        @chmod($baseline, 0600); rewind($handle); $contents = stream_get_contents($handle);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;
        if (is_array($decoded) && isset($decoded['time'], $decoded['read'], $decoded['write'])) $previous = $decoded;
        rewind($handle); ftruncate($handle, 0); fwrite($handle, (string)json_encode(['time'=>$now, 'read'=>$read, 'write'=>$write])); fflush($handle);
        flock($handle, LOCK_UN); fclose($handle);
    }
    $seconds = is_array($previous) ? max(0.1, $now - (float)$previous['time']) : 1;
    return ['read_per_second'=>is_array($previous) ? max(0, ($read - (int)$previous['read']) / $seconds) : 0,
        'write_per_second'=>is_array($previous) ? max(0, ($write - (int)$previous['write']) / $seconds) : 0];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(sh_collect());
