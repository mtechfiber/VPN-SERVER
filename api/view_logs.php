<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$ADMIN_PASSWORD = "0oomarvin";

$input = json_decode(file_get_contents("php://input"), true);

$adminPassword = trim($input["adminPassword"] ?? "");
$type = trim($input["type"] ?? "nginx_error");
$lines = (int)($input["lines"] ?? 120);

if ($adminPassword !== $ADMIN_PASSWORD) {
    echo json_encode([
        "ok" => false,
        "error" => "Invalid admin password"
    ]);
    exit;
}

if ($lines < 20) $lines = 20;
if ($lines > 500) $lines = 500;

$phpLogs = glob("/var/log/php*-fpm.log");
$phpLogs2 = glob("/var/log/php*/*fpm*.log");

if (!is_array($phpLogs)) $phpLogs = [];
if (!is_array($phpLogs2)) $phpLogs2 = [];

$phpLog = "";

foreach (array_merge($phpLogs, $phpLogs2) as $p) {
    if (is_file($p)) {
        $phpLog = $p;
        break;
    }
}

$logs = [
    "nginx_error" => [
        "label" => "Nginx Error Log",
        "path" => "/var/log/nginx/error.log"
    ],
    "nginx_access" => [
        "label" => "Nginx Access Log",
        "path" => "/var/log/nginx/access.log"
    ],
    "php_fpm" => [
        "label" => "PHP-FPM Log",
        "path" => $phpLog
    ],
    "portal_app" => [
        "label" => "Portal App Log",
        "path" => __DIR__ . "/../logs/portal.log"
    ],
    "toggle_log" => [
        "label" => "Toggle Log",
        "path" => __DIR__ . "/../logs/toggle.log"
    ]
];

if (!isset($logs[$type])) {
    echo json_encode([
        "ok" => false,
        "error" => "Invalid log type"
    ]);
    exit;
}

$log = $logs[$type];
$path = $log["path"];

if ($path === "" || !file_exists($path)) {
    echo json_encode([
        "ok" => false,
        "error" => "Log file not found",
        "label" => $log["label"],
        "path" => $path
    ]);
    exit;
}

if (!is_readable($path)) {
    echo json_encode([
        "ok" => false,
        "error" => "Log file not readable by PHP. Run setfacl command again.",
        "label" => $log["label"],
        "path" => $path
    ]);
    exit;
}

function tailFile($path, $lines) {
    $data = @file($path, FILE_IGNORE_NEW_LINES);

    if (!$data) {
        return "";
    }

    $slice = array_slice($data, -$lines);

    return implode("\n", $slice);
}

$content = tailFile($path, $lines);

// remove ANSI/control chars except newline/tab
$content = preg_replace('/[^\P{C}\n\t]/u', '', $content);

echo json_encode([
    "ok" => true,
    "type" => $type,
    "label" => $log["label"],
    "path" => $path,
    "lines" => $lines,
    "content" => $content,
    "time" => date("Y-m-d H:i:s")
], JSON_PRETTY_PRINT);
