<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$file = __DIR__ . "/../data/clients.json";

if (!file_exists($file)) {
    echo json_encode(["ok" => false, "error" => "clients.json not found"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

$username = trim($input["username"] ?? "");
$oldPassword = trim($input["oldPassword"] ?? "");
$newPassword = trim($input["newPassword"] ?? "");

if ($username === "" || $oldPassword === "" || $newPassword === "") {
    echo json_encode(["ok" => false, "error" => "Missing fields"]);
    exit;
}

if (strlen($newPassword) < 4) {
    echo json_encode(["ok" => false, "error" => "New password must be at least 4 characters"]);
    exit;
}

$clients = json_decode(file_get_contents($file), true);

if (!is_array($clients)) {
    echo json_encode(["ok" => false, "error" => "Invalid clients.json"]);
    exit;
}

$found = false;

foreach ($clients as &$client) {
    $clientUser = trim((string)($client["username"] ?? ""));

    if ($clientUser !== $username) {
        continue;
    }

    $currentPortalPass = trim((string)($client["portalPassword"] ?? ""));
    $fallbackPass = trim((string)($client["password"] ?? ""));

    $currentPass = $currentPortalPass !== "" ? $currentPortalPass : $fallbackPass;

    if ($oldPassword !== $currentPass) {
        echo json_encode(["ok" => false, "error" => "Old password is incorrect"]);
        exit;
    }

    // Portal login password only. Hindi gagalawin VPN script password.
    $client["portalPassword"] = $newPassword;

    $found = true;
    break;
}

if (!$found) {
    echo json_encode(["ok" => false, "error" => "Client not found"]);
    exit;
}

file_put_contents($file, json_encode($clients, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode([
    "ok" => true,
    "message" => "Password changed"
]);
