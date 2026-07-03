<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$file = __DIR__ . "/../data/targets.json";

if (!file_exists($file) || trim(file_get_contents($file)) === "") {
    file_put_contents($file, "{}");
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $targets = json_decode(file_get_contents($file), true);

    if (!is_array($targets)) {
        $targets = [];
    }

    echo json_encode([
        "ok" => true,
        "targets" => $targets
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $targets = $input["targets"] ?? [];

    if (!is_array($targets)) {
        echo json_encode([
            "ok" => false,
            "error" => "Invalid targets"
        ]);
        exit;
    }

    file_put_contents($file, json_encode($targets, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        "ok" => true,
        "message" => "Targets saved",
        "targets" => $targets
    ]);
    exit;
}

echo json_encode([
    "ok" => false,
    "error" => "Method not allowed"
]);
