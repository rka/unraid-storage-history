<?php
declare(strict_types=1);

$root = dirname(__DIR__); $manifest = $root . '/storage-history.plg';
$xml = simplexml_load_file($manifest);
if ($xml === false) throw new RuntimeException('Manifest XML is invalid.');
$contents = file_get_contents($manifest);
preg_match('/<!ENTITY version "([^"]+)">/', (string)$contents, $match);
$version = $match[1] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new RuntimeException('Manifest version is invalid.');
$checked = 0;
foreach ($xml->FILE as $file) {
    $url = trim((string)$file->URL); $sha = trim((string)$file->SHA256);
    if ($url === '') continue;
    if (!str_contains($url, '/v' . $version . '/')) throw new RuntimeException('Release URL is mutable: ' . $url);
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) throw new RuntimeException('Missing SHA256 for ' . $url);
    $prefix = '/v' . $version . '/'; $relative = substr($url, strpos($url, $prefix) + strlen($prefix));
    $local = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($local)) throw new RuntimeException('Release source is missing: ' . $relative);
    if (hash_file('sha256', $local) !== $sha) throw new RuntimeException('Checksum mismatch: ' . $relative);
    $checked++;
}
if ($checked < 1) throw new RuntimeException('No release files were checked.');
echo "Validated $checked release files for v$version.\n";
