<?php
header('Content-Type: application/json');
require_once __DIR__ . '/funds_store.php';

$ADMIN_PASSWORD = '0oomarvin';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$pass = $input['adminPassword'] ?? '';
if ($pass !== $ADMIN_PASSWORD) {
    echo json_encode(['ok' => false, 'message' => 'Wrong admin password']);
    exit;
}

$client = $input['client'] ?? '';
$key = fund_norm_client($client);

if ($key === '') {
    echo json_encode(['ok' => false, 'message' => 'Missing client']);
    exit;
}

$amount = $input['amount'] ?? 0;

if (!is_numeric($amount)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid amount']);
    exit;
}

$amount = round((float)$amount, 2);

if ($amount == 0) {
    echo json_encode(['ok' => false, 'message' => 'Amount cannot be zero']);
    exit;
}

$data = fund_load_all();

if (!isset($data[$key]) || !is_array($data[$key])) {
    $data[$key] = [
        'balance' => 0,
        'history' => []
    ];
}

$old = (float)($data[$key]['balance'] ?? 0);
$new = round($old + $amount, 2);

$data[$key]['balance'] = $new;
$data[$key]['updated'] = date('Y-m-d H:i:s');

if (!isset($data[$key]['history']) || !is_array($data[$key]['history'])) {
    $data[$key]['history'] = [];
}

$data[$key]['history'][] = [
    'type' => 'add',
    'amount' => $amount,
    'old_balance' => $old,
    'new_balance' => $new,
    'date' => date('Y-m-d H:i:s')
];

if (count($data[$key]['history']) > 100) {
    $data[$key]['history'] = array_slice($data[$key]['history'], -100);
}

fund_save_all($data);

echo json_encode([
    'ok' => true,
    'message' => 'Fund added',
    'client' => $key,
    'amount' => $amount,
    'balance' => $new,
    'formatted' => '₱' . number_format($new, 2)
]);
