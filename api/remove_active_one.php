<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

date_default_timezone_set("Asia/Manila");

function readTargets() {
    $file = __DIR__ . "/../data/targets.json";
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function normalizeChr($chr) {
    $chr = strtolower(trim((string)$chr));
    return strpos($chr, "1") !== false ? "chr1" : "chr2";
}

function rosRows($res) {
    if (!is_array($res)) return [];
    if (isset($res["re"]) && is_array($res["re"])) return $res["re"];

    $rows = [];
    foreach ($res as $v) {
        if (is_array($v)) $rows[] = $v;
    }
    return $rows;
}

function nameMatches($activeName, $vpnName) {
    $activeName = trim((string)$activeName);
    $vpnName = trim((string)$vpnName);

    if ($activeName === $vpnName) return true;

    // Example active name:
    // test1 | WINBOX: 57801 | EXP: JUN 17, 2027
    if (stripos($activeName, $vpnName . " |") === 0) return true;
    if (stripos($activeName, $vpnName . " ") === 0) return true;

    return false;
}

function findActiveSessions($api, $vpnName) {
    $found = [];

    $res1 = $api->comm("/ppp/active/print", [
        "?name" => $vpnName
    ]);

    foreach (rosRows($res1) as $row) {
        if (!empty($row[".id"]) && nameMatches($row["name"] ?? "", $vpnName)) {
            $found[$row[".id"]] = $row;
        }
    }

    // fallback: print all active
    $res2 = $api->comm("/ppp/active/print");

    foreach (rosRows($res2) as $row) {
        if (!empty($row[".id"]) && nameMatches($row["name"] ?? "", $vpnName)) {
            $found[$row[".id"]] = $row;
        }
    }

    return array_values($found);
}

try {
    $base = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);
    $vpnName = trim($input["vpnName"] ?? "");

    if ($vpnName === "") {
        throw new Exception("Missing vpnName");
    }

    $servers = [
        "chr1" => [
            "host" => "165.245.190.162",
            "domain" => "vpn.marvincloud1.link"
        ],
        "chr2" => [
            "host" => "152.42.226.151",
            "domain" => "vpn.marvincloud2.link"
        ]
    ];

    $targets = readTargets();
    $preferredChr = normalizeChr($targets[$vpnName] ?? "chr2");

    // Una saved CHR target, then fallback sa kabila
    $order = [$preferredChr];
    foreach (["chr1", "chr2"] as $c) {
        if (!in_array($c, $order)) $order[] = $c;
    }

    $removedTotal = 0;
    $results = [];

    foreach ($order as $chr) {
        $server = $servers[$chr];
        $api = new RouterOSAPI();

        try {
            $api->connect(
                $server["host"],
                $base["user"],
                $base["pass"],
                8728,
                $base["timeout"] ?? 5
            );

            $before = findActiveSessions($api, $vpnName);
            $removed = 0;
            $removedNames = [];

            foreach ($before as $session) {
                if (!empty($session[".id"])) {
                    $api->comm("/ppp/active/remove", [
                        ".id" => $session[".id"]
                    ]);

                    $removed++;
                    $removedTotal++;
                    $removedNames[] = $session["name"] ?? "";
                }
            }

            usleep(300000);

            $after = findActiveSessions($api, $vpnName);

            $api->disconnect();

            $results[] = [
                "chr" => $chr,
                "domain" => $server["domain"],
                "activeBeforeCount" => count($before),
                "activeRemoved" => $removed,
                "removedNames" => $removedNames,
                "activeAfterCount" => count($after)
            ];

            // Kung natanggal na sa preferred CHR, no need scan further
            if ($removed > 0) {
                break;
            }

        } catch (Exception $e) {
            try { $api->disconnect(); } catch (Exception $x) {}

            $results[] = [
                "chr" => $chr,
                "domain" => $server["domain"],
                "error" => $e->getMessage()
            ];
        }
    }

    echo json_encode([
        "ok" => true,
        "vpnName" => $vpnName,
        "mode" => "server_ppp_active_remove_only",
        "secretTouched" => false,
        "clientTouched" => false,
        "removedTotal" => $removedTotal,
        "results" => $results
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
