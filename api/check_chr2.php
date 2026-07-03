<?php
header("Content-Type: text/plain");

echo "=== CHR2 API CHECK ===\n\n";

$configFile = __DIR__ . "/config.php";
$apiFile = __DIR__ . "/routeros_api.php";

if (!file_exists($configFile)) {
    echo "FAIL: Missing config.php\n";
    exit;
}

if (!file_exists($apiFile)) {
    echo "FAIL: Missing routeros_api.php\n";
    exit;
}

$config = require $configFile;

$host = $config["host"] ?? "";
$port = $config["port"] ?? 8728;
$user = $config["user"] ?? "";
$pass = $config["pass"] ?? "";
$timeout = $config["timeout"] ?? 5;

echo "Host: {$host}\n";
echo "Port: {$port}\n";
echo "User: {$user}\n";
echo "Password: " . ($pass !== "" ? "SET" : "EMPTY") . "\n\n";

echo "[1] TCP Port Test...\n";
$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

if (!$socket) {
    echo "FAIL: Cannot connect to {$host}:{$port}\n";
    echo "Error: {$errstr} ({$errno})\n";
    echo "\nPossible problem:\n";
    echo "- CHR2 API service disabled\n";
    echo "- CHR2 firewall blocking port 8728\n";
    echo "- API address binding not allowing Ubuntu IP\n";
    echo "- Wrong CHR2 public IP\n";
    exit;
}

echo "OK: TCP port {$port} is reachable\n";
fclose($socket);

echo "\n[2] RouterOS API Login Test...\n";

try {
    require $apiFile;

    $api = new RouterOSAPI();
    $api->connect($host, $user, $pass, $port, $timeout);

    echo "OK: RouterOS API login success\n";

    echo "\n[3] Identity Test...\n";
    $identity = $api->comm("/system/identity/print");
    echo "Identity: " . ($identity["re"][0]["name"] ?? "unknown") . "\n";

    echo "\n[4] PPP Active Test...\n";
    $active = $api->comm("/ppp/active/print");

    if (empty($active["re"])) {
        echo "PPP Active: none online\n";
    } else {
        foreach ($active["re"] as $row) {
            echo "- " . ($row["name"] ?? "unknown") . " | " . ($row["address"] ?? "no-address") . "\n";
        }
    }

    echo "\n[5] PPP Secret Test...\n";
    $secrets = $api->comm("/ppp/secret/print");

    echo "PPP Secrets found: " . count($secrets["re"]) . "\n";

    $api->disconnect();

    echo "\nRESULT: CONNECTED TO CHR2 API\n";

} catch (Exception $e) {
    echo "FAIL: RouterOS API error\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nPossible problem:\n";
    echo "- Wrong API username/password\n";
    echo "- User group policy kulang\n";
    echo "- RouterOS API login blocked\n";
}
