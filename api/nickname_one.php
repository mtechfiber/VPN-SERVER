<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$file = __DIR__ . "/../data/nicknames.json";

if (!file_exists($file) || trim(file_get_contents($file)) === "") {
    file_put_contents($file, "{}");
}

function readNicknames($file) {
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $vpnName = trim($_GET["vpnName"] ?? "");
    $data = readNicknames($file);

    $nickname = "";

    if ($vpnName !== "" && isset($data[$vpnName])) {
        $nickname = trim((string)$data[$vpnName]);
    }

    echo json_encode([
        "ok" => true,
        "vpnName" => $vpnName,
        "nickname" => $nickname,
        "displayName" => $nickname !== "" ? $nickname : $vpnName
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    $vpnName = trim($input["vpnName"] ?? "");
    $nickname = trim($input["nickname"] ?? "");

    if ($vpnName === "") {
        echo json_encode([
            "ok" => false,
            "error" => "Missing vpnName"
        ]);
        exit;
    }

    $data = readNicknames($file);

    if ($nickname === "") {
        unset($data[$vpnName]);
    } else {
        $data[$vpnName] = $nickname;
    }

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        "ok" => true,
        "vpnName" => $vpnName,
        "nickname" => $nickname,
        "displayName" => $nickname !== "" ? $nickname : $vpnName
    ]);
    exit;
}

echo json_encode([
    "ok" => false,
    "error" => "Method not allowed"
]);
