<?php
header("Content-Type: application/json");

try {
    $config = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);
    $vpnNames = $input["vpnNames"] ?? [];

    if (!is_array($vpnNames)) {
        throw new Exception("vpnNames must be array");
    }

    $api = new RouterOSAPI();
    $api->connect($config["host"], $config["user"], $config["pass"], $config["port"], $config["timeout"]);

    $active = $api->comm("/ppp/active/print");

    $activeNames = [];

    foreach ($active["re"] as $row) {
        if (isset($row["name"])) {
            $activeNames[$row["name"]] = true;
        }
    }

    $statuses = [];

    foreach ($vpnNames as $name) {
        $name = trim($name);

        if ($name === "") {
            continue;
        }

        $statuses[$name] = isset($activeNames[$name]) ? "ONLINE" : "OFFLINE";
    }

    $api->disconnect();

    echo json_encode([
        "ok" => true,
        "statuses" => $statuses
    ]);
} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
