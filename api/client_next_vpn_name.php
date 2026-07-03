<?php
header('Content-Type: application/json');

$base = realpath(__DIR__ . "/..");
$dataDir = $base . "/data";

function out($a){
    echo json_encode($a, JSON_UNESCAPED_SLASHES);
    exit;
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

function rec_matches($key, $rec, $client){
    $want = keyclean($client);

    if (keyclean($key) === $want) return true;

    if (is_array($rec)) {
        foreach (["client","clientName","client_name","username","user","name","clientUsername","client_username"] as $f) {
            if (isset($rec[$f]) && keyclean($rec[$f]) === $want) return true;
        }
    }

    return false;
}

function add_name(&$names, $v){
    $v = trim((string)$v);
    if ($v === "") return;

    foreach (preg_split('/[\n,]+/', $v) as $x) {
        $x = trim($x);
        if ($x !== "") $names[] = $x;
    }
}

function collect_vpn_names($rec, &$names){
    if (!is_array($rec)) return;

    $fields = [
        "vpnNames",
        "vpn_names",
        "vpns",
        "vpn",
        "VPN Names",
        "sstpAccounts",
        "sstp_accounts",
        "vpnAccounts",
        "vpn_accounts",
        "accounts"
    ];

    foreach ($fields as $field) {
        if (!array_key_exists($field, $rec)) continue;

        $v = $rec[$field];

        if (is_string($v)) {
            add_name($names, $v);
        } elseif (is_array($v)) {
            foreach ($v as $item) {
                if (is_string($item)) {
                    add_name($names, $item);
                } elseif (is_array($item)) {
                    foreach (["name","vpnName","user","username"] as $f) {
                        if (isset($item[$f])) add_name($names, $item[$f]);
                    }
                }
            }
        }
    }
}

function scan_client_records($node, $client, &$names, $key=""){
    if (!is_array($node)) return;

    if (rec_matches($key, $node, $client)) {
        collect_vpn_names($node, $names);
    }

    foreach ($node as $k => $v) {
        if (is_array($v)) {
            if (rec_matches($k, $v, $client)) {
                collect_vpn_names($v, $names);
            }
            scan_client_records($v, $client, $names, $k);
        }
    }
}

function read_json($file){
    if (!file_exists($file)) return null;
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

function next_name($client, $names){
    $names = array_values(array_unique(array_filter(array_map('trim', $names))));

    $clientBase = preg_replace('/\d+$/', '', $client);
    if ($clientBase === "") $clientBase = $client;
    if ($clientBase === "") $clientBase = "vpn";

    $groups = [];

    foreach ($names as $n) {
        if (preg_match('/^(.+?)(\d+)$/', $n, $m)) {
            $prefix = $m[1];
            $num = (int)$m[2];

            if (!isset($groups[$prefix])) {
                $groups[$prefix] = ["count"=>0, "max"=>0];
            }

            $groups[$prefix]["count"]++;
            if ($num > $groups[$prefix]["max"]) $groups[$prefix]["max"] = $num;
        }
    }

    if (isset($groups[$clientBase])) {
        return $clientBase . ($groups[$clientBase]["max"] + 1);
    }

    if (!empty($groups)) {
        $bestPrefix = null;
        $bestCount = -1;
        $bestMax = -1;

        foreach ($groups as $prefix => $g) {
            if ($g["count"] > $bestCount || ($g["count"] === $bestCount && $g["max"] > $bestMax)) {
                $bestPrefix = $prefix;
                $bestCount = $g["count"];
                $bestMax = $g["max"];
            }
        }

        return $bestPrefix . ($bestMax + 1);
    }

    return $clientBase . "1";
}

$client = clean_client($_GET["client"] ?? $_POST["client"] ?? "");

if ($client === "") {
    out([
        "ok" => false,
        "message" => "Missing client"
    ]);
}

$names = [];

// Main client records
$clients = read_json($dataDir . "/clients.json");
if ($clients) {
    scan_client_records($clients, $client, $names);
}

// Extra generated records, kung meron
$generated = read_json($dataDir . "/client_generated_vpns.json");
if ($generated) {
    foreach ($generated as $k => $items) {
        if (keyclean($k) === keyclean($client) && is_array($items)) {
            foreach ($items as $item) {
                if (is_string($item)) add_name($names, $item);
                if (is_array($item)) {
                    foreach (["name","vpnName","user","username"] as $f) {
                        if (isset($item[$f])) add_name($names, $item[$f]);
                    }
                }
            }
        }
    }
}

$next = next_name($client, $names);

out([
    "ok" => true,
    "client" => $client,
    "existing" => array_values(array_unique($names)),
    "next" => $next
]);
