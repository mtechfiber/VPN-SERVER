<?php
header('Content-Type: application/json');

$base = realpath(__DIR__ . "/..");
$dataDir = $base . "/data";
$logDir = $base . "/logs";
$fundFile = $dataDir . "/funds.json";
$lockFile = $dataDir . "/funds.lock";
$logFile = $logDir . "/admin_fund_update.log";

if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

function out($a, $code=200){
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_SLASHES);
    exit;
}

function logx($m){
    global $logFile;
    @file_put_contents($logFile, date("Y-m-d H:i:s") . " " . $m . "\n", FILE_APPEND);
}

function norm($s){
    return strtolower(trim((string)$s));
}

function clean_client($s){
    $s = trim((string)$s);
    $s = preg_replace('/\s+(DASHBOARD|TOTAL|ONLINE|OFFLINE|AVAILABLE|FUND|SEARCH|SHOW|RESET|REFRESH|ALL).*$/i', '', $s);
    $parts = preg_split('/\s+/', trim($s));
    return trim($parts[0] ?? $s);
}

function money_val($v){
    if (is_int($v) || is_float($v)) return (float)$v;

    if (is_string($v)) {
        $s = trim($v);
        $s = str_replace(["₱", ",", " "], "", $s);
        if (is_numeric($s)) return (float)$s;
    }

    return null;
}

function read_json($file){
    if (!file_exists($file)) return [];
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

function write_json($file, $data){
    $tmp = $file . ".tmp";
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
    @chown($file, "www-data");
    @chgrp($file, "www-data");
}

function item_match($key, $value, $client){
    $want = norm($client);

    if (norm($key) === $want) return true;

    if (is_array($value)) {
        foreach (["client","clientName","client_name","name","user","username","owner","account"] as $f) {
            if (isset($value[$f]) && norm($value[$f]) === $want) return true;
        }
    }

    return false;
}

function amount_from_value($v){
    $m = money_val($v);
    if ($m !== null) return $m;

    if (is_array($v)) {
        foreach (["balance","fund","funds","amount","available","availableFund","available_fund","fundBalance","fund_balance","total"] as $f) {
            if (array_key_exists($f, $v)) {
                $m = money_val($v[$f]);
                if ($m !== null) return $m;
            }
        }
    }

    return 0;
}

function locate_fund(&$data, $client){
    foreach ($data as $k => &$v) {
        if (item_match($k, $v, $client)) {
            return ["type"=>"root", "key"=>$k];
        }
    }

    foreach (["balances","funds","clients","data","items"] as $container) {
        if (!isset($data[$container]) || !is_array($data[$container])) continue;

        foreach ($data[$container] as $k => &$v) {
            if (item_match($k, $v, $client)) {
                return ["type"=>"container", "container"=>$container, "key"=>$k];
            }
        }
    }

    return null;
}

function get_balance(&$data, $client, $loc){
    if (!$loc) return 0;

    if ($loc["type"] === "root") {
        return amount_from_value($data[$loc["key"]]);
    }

    if ($loc["type"] === "container") {
        return amount_from_value($data[$loc["container"]][$loc["key"]]);
    }

    return 0;
}

function set_money(&$target, $amount){
    $amount = round((float)$amount, 2);

    if (is_numeric($target) || is_string($target)) {
        $target = $amount;
        return;
    }

    if (is_array($target)) {
        foreach (["balance","fund","funds","amount","available","availableFund","available_fund","fundBalance","fund_balance","total"] as $f) {
            if (array_key_exists($f, $target) && money_val($target[$f]) !== null) {
                $target[$f] = $amount;
                return;
            }
        }

        $target["balance"] = $amount;
        return;
    }

    $target = $amount;
}

function set_balance(&$data, $client, $loc, $amount){
    $amount = round((float)$amount, 2);

    if (!$loc) {
        $data[$client] = $amount;
        return;
    }

    if ($loc["type"] === "root") {
        $k = $loc["key"];
        set_money($data[$k], $amount);
        return;
    }

    if ($loc["type"] === "container") {
        $c = $loc["container"];
        $k = $loc["key"];
        set_money($data[$c][$k], $amount);
        return;
    }

    $data[$client] = $amount;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) $input = array_merge($_GET, $_POST);

$client = clean_client($input["client"] ?? "");
$action = strtolower(trim((string)($input["action"] ?? "get")));
$amount = money_val($input["amount"] ?? 0);

if ($client === "") out(["ok"=>false, "message"=>"Missing client"], 400);
if ($amount === null) $amount = 0;

$fp = fopen($lockFile, "c+");
if (!$fp) out(["ok"=>false, "message"=>"Cannot open lock"], 500);

flock($fp, LOCK_EX);

$data = read_json($fundFile);
$loc = locate_fund($data, $client);
$old = get_balance($data, $client, $loc);

if ($action === "get") {
    flock($fp, LOCK_UN);
    fclose($fp);

    out([
        "ok"=>true,
        "client"=>$client,
        "balance"=>round($old, 2)
    ]);
}

if ($action === "add") {
    if ($amount <= 0) {
        flock($fp, LOCK_UN);
        fclose($fp);
        out(["ok"=>false, "message"=>"Invalid amount"], 400);
    }

    $new = round($old + $amount, 2);
    set_balance($data, $client, $loc, $new);
    write_json($fundFile, $data);

    logx("ADD client=$client amount=$amount old=$old new=$new");

    flock($fp, LOCK_UN);
    fclose($fp);

    out([
        "ok"=>true,
        "action"=>"add",
        "client"=>$client,
        "oldBalance"=>round($old, 2),
        "balance"=>$new
    ]);
}

if ($action === "set") {
    if ($amount < 0) {
        flock($fp, LOCK_UN);
        fclose($fp);
        out(["ok"=>false, "message"=>"Invalid fund"], 400);
    }

    $new = round($amount, 2);
    set_balance($data, $client, $loc, $new);
    write_json($fundFile, $data);

    logx("SET client=$client old=$old new=$new");

    flock($fp, LOCK_UN);
    fclose($fp);

    out([
        "ok"=>true,
        "action"=>"set",
        "client"=>$client,
        "oldBalance"=>round($old, 2),
        "balance"=>$new
    ]);
}

flock($fp, LOCK_UN);
fclose($fp);

out(["ok"=>false, "message"=>"Invalid action"], 400);
