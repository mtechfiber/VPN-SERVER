<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$file = __DIR__ . "/../data/clients.json";

if (!file_exists($file) || trim(file_get_contents($file)) === "") {
    file_put_contents($file, "[]");
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $clients = json_decode(file_get_contents($file), true);
    if (!is_array($clients)) $clients = [];

    echo json_encode([
        "ok" => true,
        "clients" => $clients,
        "count" => count($clients)
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $clients = $input["clients"] ?? [];

    if (!is_array($clients)) {
        echo json_encode(["ok" => false, "error" => "Invalid clients"]);
        exit;
    }

    file_put_contents($file, json_encode($clients, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        "ok" => true,
        "message" => "Saved",
        "count" => count($clients)
    ]);
    exit;
}

echo json_encode(["ok" => false, "error" => "Method not allowed"]);
