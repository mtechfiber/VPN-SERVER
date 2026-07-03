<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$file = __DIR__ . "/../data/targets.json";

if (!file_exists($file) || trim(file_get_contents($file)) === "") {
    file_put_contents($file, "{}");
}

function readTargets($file) {
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function normalizeChr($chr) {
    $chr = strtolower(trim((string)$chr));

    if (strpos($chr, "1") !== false) {
        return "chr1";
    }

    return "chr2";
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $vpnName = trim($_GET["vpnName"] ?? "");
    $targets = readTargets($file);

    $chr = "chr2";

    if ($vpnName !== "" && isset($targets[$vpnName])) {
        $chr = normalizeChr($targets[$vpnName]);
    }

    echo json_encode([
        "ok" => true,
        "vpnName" => $vpnName,
        "chr" => $chr,
        "targets" => $targets
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    $vpnName = trim($input["vpnName"] ?? "");
    $chr = normalizeChr($input["chr"] ?? "chr2");

    if ($vpnName === "") {
        echo json_encode([
            "ok" => false,
            "error" => "Missing vpnName"
        ]);
        exit;
    }

    $targets = readTargets($file);
    $targets[$vpnName] = $chr;

    file_put_contents($file, json_encode($targets, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        "ok" => true,
        "vpnName" => $vpnName,
        "chr" => $chr,
        "targets" => $targets
    ]);
    exit;
}

echo json_encode([
    "ok" => false,
    "error" => "Method not allowed"
]);
