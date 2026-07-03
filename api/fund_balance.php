<?php
header('Content-Type: application/json');
require_once __DIR__ . '/funds_store.php';

$client = $_GET['client'] ?? $_POST['client'] ?? '';
$key = fund_norm_client($client);

if ($key === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing client']);
    exit;
}

$data = fund_load_all();
$item = $data[$key] ?? ['balance' => 0, 'history' => []];

$balance = (float)($item['balance'] ?? 0);

echo json_encode([
    'ok' => true,
    'client' => $key,
    'balance' => $balance,
    'formatted' => '₱' . number_format($balance, 2)
]);
