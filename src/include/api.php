<?php
declare(strict_types=1);
require_once __DIR__ . '/history.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(sh_payload((string)($_GET['range'] ?? '30d')), JSON_UNESCAPED_SLASHES);

