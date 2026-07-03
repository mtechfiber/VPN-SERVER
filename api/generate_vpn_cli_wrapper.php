<?php
// Direct runner for generate_vpn.php to avoid Nginx 400 Bad Request

$payloadFile = $argv[1] ?? "";

if ($payloadFile === "" || !file_exists($payloadFile)) {
    echo json_encode(["ok"=>false, "error"=>"Missing payload file"]);
    exit;
}

$payload = json_decode(file_get_contents($payloadFile), true);

if (!is_array($payload)) {
    echo json_encode(["ok"=>false, "error"=>"Invalid payload JSON"]);
    exit;
}

$user = trim((string)($payload["user"] ?? $payload["User"] ?? $payload["username"] ?? $payload["name"] ?? $payload["vpnName"] ?? ""));
$pass = trim((string)($payload["password"] ?? $payload["Password"] ?? $payload["pass"] ?? ""));
$profile = trim((string)($payload["profile"] ?? "1mbps"));

if ($user !== "") {
    $payload["user"] = $user;
    $payload["User"] = $user;
    $payload["username"] = $user;
    $payload["name"] = $user;
    $payload["vpnName"] = $user;
}

if ($pass !== "") {
    $payload["password"] = $pass;
    $payload["pass"] = $pass;
}

if ($profile === "") $profile = "1mbps";
$payload["profile"] = $profile;

$_POST = $payload;
$_GET = $payload;
$_REQUEST = $payload;

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"] = "/api/generate_vpn.php";
$_SERVER["SCRIPT_NAME"] = "/api/generate_vpn.php";
$_SERVER["HTTP_HOST"] = "marvincloud.link";

chdir(__DIR__);

ob_start();
include __DIR__ . "/generate_vpn.php";
$out = ob_get_clean();

echo $out;
