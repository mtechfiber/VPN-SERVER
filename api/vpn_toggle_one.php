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

function rscQuote($text) {
    $text = str_replace("\\", "\\\\", (string)$text);
    $text = str_replace('"', '\"', $text);
    return '"' . $text . '"';
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

function findActiveSessions($api, $vpnName) {
    $found = [];
    $all = $api->comm("/ppp/active/print");

    foreach (rosRows($all) as $row) {
        $name = $row["name"] ?? "";

        if (!empty($row[".id"]) && nameMatches($name, $vpnName)) {
            $found[$row[".id"]] = $row;
        }
    }

    return array_values($found);
}

function removeActiveByChrScript($api, $vpnName) {
    $scriptName = "portal-kick-" . time() . "-" . random_int(1000, 9999);

    // RouterOS mismo ang magre-remove:
    // /ppp active remove [find where name~"^test1"]
    $pattern = "^" . $vpnName;
    $source = "/ppp active remove [find where name~" . rscQuote($pattern) . "];";

    $add = $api->comm("/system/script/add", [
        "name" => $scriptName,
        "source" => $source
    ]);

    usleep(300000);

    $scripts = rosRows($api->comm("/system/script/print", [
        "?name" => $scriptName
    ]));

    $scriptId = "";

    if (!empty($scripts)) {
        $scriptId = $scripts[0][".id"] ?? "";
    }

    $runResponses = [];

    if ($scriptId !== "") {
        $runResponses[] = $api->comm("/system/script/run", [
            ".id" => $scriptId
        ]);
    }

    // fallback run by name
    $runResponses[] = $api->comm("/system/script/run", [
        "number" => $scriptName
    ]);

    usleep(800000);

    if ($scriptId !== "") {
        $api->comm("/system/script/remove", [
            ".id" => $scriptId
        ]);
    }

    return [
        "scriptName" => $scriptName,
        "scriptId" => $scriptId,
        "scriptSource" => $source,
        "addResponse" => $add,
        "runResponses" => $runResponses
    ];
}

try {
    $base = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);

    $vpnName = trim($input["vpnName"] ?? "");
    $enabled = (bool)($input["enabled"] ?? false);

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

    $api = null;
    $usedChr = null;
    $usedServer = null;
    $secret = null;

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

            if ($foundSecret) {
                $api = $tryApi;
                $usedChr = $chr;
                $usedServer = $server;
                $secret = $foundSecret;
                break;
            }

            $tryApi->disconnect();

        } catch (Throwable $e) {
            try { $tryApi->disconnect(); } catch (Throwable $x) {}
        }
    }

    if (!$api || !$secret || !$usedChr) {
        throw new Exception("PPP secret not found on CHR1 or CHR2: " . $vpnName);
    }

    $secretId = $secret[".id"] ?? "";
    $secretName = $secret["name"] ?? "";

    if ($secretId === "") {
        throw new Exception("Secret ID not found");
    }

    $activeBefore = findActiveSessions($api, $vpnName);
    $kickScript = null;

    if ($enabled) {
        $api->comm("/ppp/secret/set", [
            ".id" => $secretId,
            "disabled" => "no"
        ]);

        $action = "enabled";

    } else {
        $api->comm("/ppp/secret/set", [
            ".id" => $secretId,
            "disabled" => "yes"
        ]);

        usleep(500000);

        $kickScript = removeActiveByChrScript($api, $vpnName);

        usleep(1000000);

        $action = "disabled";
    }

    $secretAfter = findSecret($api, $vpnName);
    $secretDisabledNow = null;

    if ($secretAfter) {
        $d = strtolower($secretAfter["disabled"] ?? "");
        $secretDisabledNow = ($d === "true" || $d === "yes");
    }

    $activeAfter = findActiveSessions($api, $vpnName);

    $api->disconnect();

    sendJson([
        "ok" => true,
        "vpnNameRequest" => $vpnName,
        "secretNameFound" => $secretName,
        "chr" => $usedChr,
        "domain" => $usedServer["domain"],
        "action" => $action,
        "enabledRequest" => $enabled,
        "secretDisabledNow" => $secretDisabledNow,

        "activeBeforeCount" => count($activeBefore),
        "activeBeforeNames" => array_map(function($x){ return $x["name"] ?? ""; }, $activeBefore),

        "kickScript" => $kickScript,

        "activeAfterCount" => count($activeAfter),
        "activeAfterNames" => array_map(function($x){ return $x["name"] ?? ""; }, $activeAfter)
    ]);

} catch (Throwable $e) {
    sendJson([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
