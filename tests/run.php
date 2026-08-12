<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/include/history.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

expect(sh_valid_data_path('/mnt/user/system/storage-history'), 'normal /mnt path rejected');
foreach ([
    '/boot/storage-history', '/mnt/../boot/storage-history', '/mnt/user//history',
    "/mnt/user/history\ninterval=daily", '/mnt/user/history;touch', '/mnt/user/history/',
] as $path) expect(!sh_valid_data_path($path), 'unsafe path accepted: ' . json_encode($path));

$now = time();
$valid = ['timestamp'=>$now, 'total'=>1000, 'used'=>400, 'free'=>600, 'source'=>'array'];
expect(sh_validate_sample($valid) === $valid, 'valid sample changed');
$invalid = $valid; $invalid['used'] = 2000;
expect(sh_validate_sample($invalid) === null, 'out-of-bounds sample accepted');
$invalid = $valid; $invalid['timestamp'] = $now + 172800;
expect(sh_validate_sample($invalid) === null, 'future sample accepted');

$samples = [];
for ($index = 0; $index < 5000; $index++) $samples[] = [
    'timestamp'=>$now - 5000 + $index, 'total'=>100000, 'used'=>$index === 2511 ? 90000 : 10000 + $index,
    'free'=>80000, 'source'=>'array',
];
$reduced = sh_downsample($samples);
expect(count($reduced) <= SH_MAX_RESPONSE_SAMPLES, 'downsample limit exceeded');
expect(max(array_column($reduced, 'used')) === 90000, 'downsampling removed an extrema spike');
expect($reduced[0]['timestamp'] === $samples[0]['timestamp'], 'first sample removed');
expect($reduced[count($reduced) - 1]['timestamp'] === $samples[count($samples) - 1]['timestamp'], 'last sample removed');

echo "Storage History tests passed.\n";
