<?php
header('Content-Type: application/json');

$base = realpath(__DIR__ . "/..");
$dataDir = $base . "/data";
$clientsFile = $dataDir . "/clients.json";
$targetsFile = $dataDir . "/targets.json";
$logFile = $base . "/logs/client_add_vpn_to_record.log";

function out($a, $code=200){
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_SLASHES);
    exit;
}

function logx($m){
    global $logFile;
    @file_put_contents($logFile, date("Y-m-d H:i:s") . " " . $m . "\n", FILE_APPEND);
}

function clean_client($s){
    $s = trim((string)$s);
    $s = preg_replace('/\s+(DASHBOARD|TOTAL|ONLINE|OFFLINE|AVAILABLE|FUND|SEARCH|SHOW|RESET|REFRESH|ALL).*$/i', '', $s);
    $parts = preg_split('/\s+/', trim($s));
    return trim($parts[0] ?? $s);
}

function keyclean($s){
    return strtolower(trim((string)$s));
}

function record_matches($key, $rec, $client){
    $want = keyclean($client);

    if (keyclean($key) === $want) return true;

    if (is_array($rec)) {
        foreach (["client","clientName","client_name","username","user","name","clientUsername","client_username"] as $f) {
            if (isset($rec[$f]) && keyclean($rec[$f]) === $want) return true;
        }
    }

    return false;
}

function list_has_vpn($list, $vpn){
    $want = keyclean($vpn);

    if (!is_array($list)) return false;

    foreach ($list as $item) {
        if (is_string($item) && keyclean($item) === $want) return true;

        if (is_array($item)) {
            foreach (["name","vpnName","user","username"] as $f) {
                if (isset($item[$f]) && keyclean($item[$f]) === $want) return true;
            }
        }
    }

    return false;
}

function add_vpn_to_rec(&$rec, $vpn){
    if (!is_array($rec)) return false;

    $fields = [
        "vpnNames",
        "vpn_names",
        "vpns",
        "vpn",
        "sstpAccounts",
        "sstp_accounts",
        "vpnAccounts",
        "vpn_accounts",
        "accounts"
    ];

    foreach ($fields as $field) {
        if (array_key_exists($field, $rec)) {
            if (!is_array($rec[$field])) {
                $old = trim((string)$rec[$field]);
                $rec[$field] = $old !== "" ? [$old] : [];
            }

            if (!list_has_vpn($rec[$field], $vpn)) {
                $rec[$field][] = $vpn;
                return true;
            }

            return false;
        }
    }

    $rec["vpnNames"] = [$vpn];
    return true;
}

function update_recursive(&$node, $client, $vpn, $key=""){
    if (!is_array($node)) return false;

    if (record_matches($key, $node, $client)) {
        return add_vpn_to_rec($node, $vpn);
    }

    $changed = false;

    foreach ($node as $k => &$v) {
        if (is_array($v)) {
            if (record_matches($k, $v, $client)) {
                if (add_vpn_to_rec($v, $vpn)) $changed = true;
            } else {
                if (update_recursive($v, $client, $vpn, $k)) $changed = true;
            }
        }
    }

    return $changed;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) $input = $_POST;

$client = clean_client($input["client"] ?? "");
$vpn = trim((string)($input["vpnName"] ?? $input["user"] ?? ""));
$domain = trim((string)($input["domain"] ?? ""));

if ($client === "" || $vpn === "") {
    out(["ok"=>false, "message"=>"Missing client or vpnName"], 400);
}

if (!file_exists($clientsFile)) {
    out(["ok"=>false, "message"=>"clients.json not found"], 404);
}

$data = json_decode(file_get_contents($clientsFile), true);

if (!is_array($data)) {
    out(["ok"=>false, "message"=>"Invalid clients.json"], 500);
}

$changed = update_recursive($data, $client, $vpn);

if ($changed) {
    $tmp = $clientsFile . ".tmp";
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $clientsFile);
    @chown($clientsFile, "www-data");
    @chgrp($clientsFile, "www-data");
}

if ($domain !== "") {
    $targets = [];

    if (file_exists($targetsFile)) {
        $t = json_decode(file_get_contents($targetsFile), true);
        if (is_array($t)) $targets = $t;
    }

    $targets[$vpn] = $domain;

    $tmpT = $targetsFile . ".tmp";
    file_put_contents($tmpT, json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmpT, $targetsFile);
    @chown($targetsFile, "www-data");
    @chgrp($targetsFile, "www-data");
}

logx("client=$client vpn=$vpn domain=$domain changed=" . ($changed ? "yes" : "no"));

out([
    "ok"=>true,
    "client"=>$client,
    "vpnName"=>$vpn,
    "domain"=>$domain,
    "changed"=>$changed
]);
