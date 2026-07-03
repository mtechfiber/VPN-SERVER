<?php
ob_start();

header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

date_default_timezone_set("Asia/Manila");

function sendJson($data) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function readTargets() {
    $file = __DIR__ . "/../data/targets.json";

    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function normalizeChr($chr) {
    $chr = strtolower(trim((string)$chr));
    return strpos($chr, "1") !== false ? "chr1" : "chr2";
}

function rosRows($res) {
    if (!is_array($res)) return [];

    if (isset($res["re"]) && is_array($res["re"])) {
        return $res["re"];
    }

    $rows = [];

    foreach ($res as $v) {
        if (is_array($v)) {
            $rows[] = $v;
        }
    }

    return $rows;
}

function nameMatches($actualName, $vpnName) {
    $actualName = trim((string)$actualName);
    $vpnName = trim((string)$vpnName);

    if ($actualName === $vpnName) return true;

    // Match:
    // test1 | WINBOX: 57801 | EXP: JUN 17, 2027
    if (stripos($actualName, $vpnName . " |") === 0) return true;
    if (stripos($actualName, $vpnName . " ") === 0) return true;

    return false;
}

function parseExpiryFromComment($text) {
    $result = [
        "exp" => "",
        "expired" => false,
        "daysLeft" => null,
        "dayLeftText" => "NO EXP"
    ];

    if (!preg_match('/EXP:\s*([A-Z]{3}\s+\d{1,2},\s+\d{4})/i', $text, $m)) {
        return $result;
    }

    $expText = strtoupper(trim($m[1]));
    $tz = new DateTimeZone("Asia/Manila");
    $expDate = DateTime::createFromFormat("M d, Y", $expText, $tz);

    if (!$expDate) {
        return $result;
    }

    $expDate->setTime(23, 59, 59);
    $now = new DateTime("now", $tz);

    $diff = $now->diff($expDate);
    $daysLeft = (int)$diff->format("%r%a");
    $expired = $now > $expDate;

    $result["exp"] = $expText;
    $result["expired"] = $expired;
    $result["daysLeft"] = max(0, $daysLeft);
    $result["dayLeftText"] = $expired ? "EXPIRED" : max(0, $daysLeft) . " day(s)";

    return $result;
}

function findSecret($api, $vpnName) {
    $all = $api->comm("/ppp/secret/print");

    foreach (rosRows($all) as $row) {
        $name = $row["name"] ?? "";
        $comment = $row["comment"] ?? "";

        if (!empty($row[".id"]) && (nameMatches($name, $vpnName) || nameMatches($comment, $vpnName))) {
            return $row;
        }
    }

    return null;
}

function findActive($api, $vpnName) {
    $all = $api->comm("/ppp/active/print");

    foreach (rosRows($all) as $row) {
        $name = $row["name"] ?? "";

        if (!empty($row[".id"]) && nameMatches($name, $vpnName)) {
            return $row;
        }
    }

    return null;
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

    $order = [$preferredChr];

    foreach (["chr1", "chr2"] as $chr) {
        if (!in_array($chr, $order)) {
            $order[] = $chr;
        }
    }

    $usedChr = null;
    $usedServer = null;
    $secret = null;
    $active = null;
    $api = null;

    foreach ($order as $chr) {
        $server = $servers[$chr];
        $tryApi = new RouterOSAPI();

        try {
            $tryApi->connect(
                $server["host"],
                $base["user"],
                $base["pass"],
                8728,
                $base["timeout"] ?? 5
            );

            $foundSecret = findSecret($tryApi, $vpnName);
            $foundActive = findActive($tryApi, $vpnName);

            if ($foundSecret || $foundActive) {
                $usedChr = $chr;
                $usedServer = $server;
                $secret = $foundSecret;
                $active = $foundActive;
                $api = $tryApi;
                break;
            }

            $tryApi->disconnect();

        } catch (Throwable $e) {
            try { $tryApi->disconnect(); } catch (Throwable $x) {}
        }
    }

    if (!$api || !$usedChr) {
        sendJson([
            "ok" => true,
            "vpnName" => $vpnName,
            "exists" => false,
            "online" => false,
            "disabled" => true,
            "enabled" => false,
            "uptime" => "",
            "uptimeText" => "OFFLINE",
            "dayLeftText" => "NO EXP",
            "source" => "not found"
        ]);
    }

    $exists = $secret ? true : false;
    $online = $active ? true : false;

    $disabled = true;
    $comment = "";
    $secretName = "";

    if ($secret) {
        $secretName = $secret["name"] ?? "";
        $comment = $secret["comment"] ?? "";

        $d = strtolower($secret["disabled"] ?? "false");
        $disabled = ($d === "true" || $d === "yes");
    }

    // Kung walang comment pero nasa name yung EXP, doon kukuha.
    $expirySource = $comment !== "" ? $comment : ($secretName ?: ($active["name"] ?? ""));
    $exp = parseExpiryFromComment($expirySource);

    $uptime = "";
    $activeName = "";
    $activeAddress = "";
    $activeCallerId = "";

    if ($active) {
        $uptime = $active["uptime"] ?? "";
        $activeName = $active["name"] ?? "";
        $activeAddress = $active["address"] ?? "";
        $activeCallerId = $active["caller-id"] ?? "";
    }

    $api->disconnect();

    sendJson([
        "ok" => true,
        "vpnName" => $vpnName,
        "chr" => $usedChr,
        "domain" => $usedServer["domain"],
        "exists" => $exists,
        "online" => $online,
        "disabled" => $disabled,
        "enabled" => !$disabled,
        "comment" => $comment,
        "secretName" => $secretName,

        "activeName" => $activeName,
        "activeAddress" => $activeAddress,
        "activeCallerId" => $activeCallerId,
        "uptime" => $uptime,
        "uptimeText" => $online ? ($uptime ?: "ONLINE") : "OFFLINE",

        "exp" => $exp["exp"],
        "expired" => $exp["expired"],
        "daysLeft" => $exp["daysLeft"],
        "dayLeftText" => $exp["dayLeftText"],

        "source" => "PPP Secret comment/name + PPP Active uptime"
    ]);

} catch (Throwable $e) {
    sendJson([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
