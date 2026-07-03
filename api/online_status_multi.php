<?php
header("Content-Type: application/json");

try {
    $base = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);
    $items = $input["items"] ?? [];

    if (!is_array($items)) {
        throw new Exception("items must be array");
    }

    $servers = [
        "chr1" => [
            "host" => "165.245.190.162",
            "port" => 8728,
            "user" => $base["user"],
            "pass" => $base["pass"],
            "timeout" => 5
        ],
        "chr2" => [
            "host" => "152.42.226.151",
            "port" => 8728,
            "user" => $base["user"],
            "pass" => $base["pass"],
            "timeout" => 5
        ]
    ];

    $groups = [
        "chr1" => [],
        "chr2" => []
    ];

    foreach ($items as $item) {
        $name = trim($item["name"] ?? "");
        $chr = trim($item["chr"] ?? "chr2");

        if ($name === "") {
            continue;
        }

        if (!isset($servers[$chr])) {
            $chr = "chr2";
        }

        $groups[$chr][] = $name;
    }

    $statuses = [];

    foreach ($groups as $chr => $names) {
        if (count($names) === 0) {
            continue;
        }

        $srv = $servers[$chr];

        try {
            $api = new RouterOSAPI();
            $api->connect(
                $srv["host"],
                $srv["user"],
                $srv["pass"],
                $srv["port"],
                $srv["timeout"]
            );

            $active = $api->comm("/ppp/active/print");

            $activeNames = [];

            foreach ($active["re"] as $row) {
                if (isset($row["name"])) {
                    $activeNames[$row["name"]] = true;
                }
            }

            foreach ($names as $name) {
                $statuses[$name] = [
                    "status" => isset($activeNames[$name]) ? "ONLINE" : "OFFLINE",
                    "chr" => $chr
                ];
            }

            $api->disconnect();

        } catch (Exception $e) {
            foreach ($names as $name) {
                $statuses[$name] = [
                    "status" => "API ERROR",
                    "chr" => $chr,
                    "error" => $e->getMessage()
                ];
            }
        }
    }

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
