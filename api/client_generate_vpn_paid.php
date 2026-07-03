<?php
header('Content-Type: application/json');

$PRICE = 300;
$BASE = realpath(__DIR__ . "/..");
$DATA_DIR = $BASE . "/data";
$LOG_DIR = $BASE . "/logs";
$LOCK_FILE = $DATA_DIR . "/paid_generate.lock";
$LOG_FILE = $LOG_DIR . "/client_generate_paid.log";

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);

function out($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    exit;
}

function logx($msg){
    global $LOG_FILE;
    @file_put_contents($LOG_FILE, date("Y-m-d H:i:s") . " " . $msg . "\n", FILE_APPEND);
}

function clean_client_name_paid($s){
    $s = trim((string)$s);
    $s = preg_replace('/\s+(DASHBOARD|TOTAL|ONLINE|OFFLINE|AVAILABLE|FUND|SEARCH|SHOW|RESET|REFRESH|ALL).*$/i', '', $s);
    $parts = preg_split('/\s+/', trim($s));
    return trim($parts[0] ?? $s);
}

function norm($s){
    return strtolower(trim((string)$s));
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

function client_match($key, $value, $client){
    $want = norm($client);

    if (norm($key) === $want) return true;

    if (is_array($value)) {
        foreach (["client","clientName","client_name","name","user","username","owner","account"] as $f) {
            if (isset($value[$f]) && norm($value[$f]) === $want) return true;
        }
    }

    return false;
}

function find_money_path($value, $basePath, $directScalar = false, $depth = 0){
    if ($depth > 6) return null;

    if ($directScalar) {
        $m = money_val($value);
        if ($m !== null) return ["path"=>$basePath, "balance"=>$m];
    }

    if (!is_array($value)) return null;

    $fields = [
        "balance",
        "fund",
        "funds",
        "amount",
        "available",
        "availableFund",
        "available_fund",
        "fundBalance",
        "fund_balance",
        "wallet",
        "total"
    ];

    foreach ($fields as $f) {
        if (array_key_exists($f, $value)) {
            $m = money_val($value[$f]);
            if ($m !== null) {
                return ["path"=>array_merge($basePath, [$f]), "balance"=>$m];
            }

            if (is_array($value[$f])) {
                $r = find_money_path($value[$f], array_merge($basePath, [$f]), true, $depth + 1);
                if ($r) return $r;
            }
        }
    }

    foreach ($value as $k => $v) {
        if (is_array($v)) {
            $r = find_money_path($v, array_merge($basePath, [$k]), false, $depth + 1);
            if ($r) return $r;
        }
    }

    return null;
}

function find_client_fund_path($data, $client, $path = [], $depth = 0){
    if ($depth > 8 || !is_array($data)) return null;

    foreach ($data as $k => $v) {
        $newPath = array_merge($path, [$k]);

        if (client_match($k, $v, $client)) {
            if (!is_array($v)) {
                $m = money_val($v);
                if ($m !== null) return ["path"=>$newPath, "balance"=>$m];
            }

            $r = find_money_path($v, $newPath, false);
            if ($r) return $r;
        }

        if (is_array($v)) {
            $r = find_client_fund_path($v, $client, $newPath, $depth + 1);
            if ($r) return $r;
        }
    }

    return null;
}

function &ref_path(&$arr, $path){
    $ref =& $arr;
    foreach ($path as $p) {
        $ref =& $ref[$p];
    }
    return $ref;
}

function json_files_priority(){
    global $DATA_DIR;

    $priority = [
        $DATA_DIR . "/funds.json",
        $DATA_DIR . "/clients.json",
        $DATA_DIR . "/client_funds.json",
        $DATA_DIR . "/clients_data.json"
    ];

    $files = [];
    foreach ($priority as $f) {
        if (file_exists($f)) $files[] = $f;
    }

    foreach (glob($DATA_DIR . "/*.json") ?: [] as $f) {
        $bn = basename($f);
        if (in_array($f, $files, true)) continue;
        if (stripos($bn, "backup") !== false) continue;
        if (in_array($bn, ["chr_api.json","targets.json","servers.json"], true)) continue;
        $files[] = $f;
    }

    return $files;
}

function find_client_balance_location($client){
    foreach (json_files_priority() as $file) {
        $raw = @file_get_contents($file);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) continue;

        $loc = find_client_fund_path($data, $client);
        if ($loc && isset($loc["path"])) {
            return [
                "file"=>$file,
                "data"=>$data,
                "path"=>$loc["path"],
                "balance"=>(float)$loc["balance"]
            ];
        }
    }

    return null;
}

function write_json($file, $data){
    $tmp = $file . ".tmp";
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
}

function adjust_balance($client, $delta){
    $loc = find_client_balance_location($client);
    if (!$loc) return [false, "Fund record not found for client: $client", null];

    $data = $loc["data"];
    $balance = (float)$loc["balance"];
    $newBalance = round($balance + $delta, 2);

    if ($newBalance < -0.001) {
        return [false, "Insufficient fund. Need ₱300.00", [
            "balance"=>$balance,
            "file"=>basename($loc["file"]),
            "path"=>$loc["path"]
        ]];
    }

    $ref =& ref_path($data, $loc["path"]);
    $ref = $newBalance;

    write_json($loc["file"], $data);

    return [true, "OK", [
        "oldBalance"=>$balance,
        "newBalance"=>$newBalance,
        "file"=>basename($loc["file"]),
        "path"=>$loc["path"]
    ]];
}

function generate_success_raw($raw){
    $j = json_decode((string)$raw, true);

    if (!is_array($j)) return false;

    $msg = (string)($j["message"] ?? "");
    $hasError = isset($j["error"]) || (isset($j["ok"]) && $j["ok"] === false);

    if ($hasError) return false;

    return (
        (isset($j["ok"]) && $j["ok"] === true) ||
        stripos($msg, "generated") !== false ||
        stripos($msg, "pushed") !== false ||
        isset($j["script"]) ||
        isset($j["address"]) ||
        isset($j["clientScript"])
    );
}

function send_form_post($url, $payload){
    $post = http_build_query($payload);

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $raw, $err];
    }

    $ctx = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/x-www-form-urlencoded
Host: marvincloud.link
",
            "content" => $post,
            "timeout" => 60,
            "ignore_errors" => true
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    return [0, $raw, $raw === false ? "file_get_contents failed" : ""];
}

function send_json_post($url, $payload){
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $raw, $err];
    }

    $ctx = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json
Host: marvincloud.link
",
            "content" => $json,
            "timeout" => 60,
            "ignore_errors" => true
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    return [0, $raw, $raw === false ? "file_get_contents failed" : ""];
}



function paid_auto_add_vpn_to_client_list_final($client, $vpnName, $domain = ""){
    global $DATA_DIR;

    try {
        $client = trim((string)$client);
        $vpnName = trim((string)$vpnName);
        $domain = trim((string)$domain);

        if ($client === "" || $vpnName === "") {
            logx("AUTO_ADD_SKIP missing client/vpn client=$client vpn=$vpnName");
            return false;
        }

        $file = $DATA_DIR . "/clients.json";

        if (!file_exists($file)) {
            logx("AUTO_ADD_FAIL clients.json missing");
            return false;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!is_array($data)) {
            logx("AUTO_ADD_FAIL clients.json invalid");
            return false;
        }

        $want = strtolower($client);
        $changed = false;

        $matchClient = function($key, $rec) use ($want) {
            if (strtolower(trim((string)$key)) === $want) return true;

            if (is_array($rec)) {
                foreach (["client","clientName","client_name","username","user","name","clientUsername","client_username"] as $f) {
                    if (isset($rec[$f]) && strtolower(trim((string)$rec[$f])) === $want) {
                        return true;
                    }
                }
            }

            return false;
        };

        $hasVpn = function($list, $vpnName) {
            if (!is_array($list)) return false;

            $wantVpn = strtolower(trim((string)$vpnName));

            foreach ($list as $item) {
                if (is_string($item) && strtolower(trim($item)) === $wantVpn) return true;

                if (is_array($item)) {
                    foreach (["name","vpnName","user","username"] as $f) {
                        if (isset($item[$f]) && strtolower(trim((string)$item[$f])) === $wantVpn) {
                            return true;
                        }
                    }
                }
            }

            return false;
        };

        $addVpn = function(&$rec) use ($vpnName, $hasVpn, &$changed) {
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
                if (array_key_exists($field, $rec)) {
                    if (!is_array($rec[$field])) {
                        $old = trim((string)$rec[$field]);
                        $rec[$field] = $old !== "" ? [$old] : [];
                    }

                    if (!$hasVpn($rec[$field], $vpnName)) {
                        $rec[$field][] = $vpnName;
                        $changed = true;
                    }

                    return;
                }
            }

            // default field kapag walang existing vpn field
            $rec["vpnNames"] = [$vpnName];
            $changed = true;
        };

        $walk = function(&$node, $key = "") use (&$walk, $matchClient, $addVpn) {
            if (!is_array($node)) return;

            if ($matchClient($key, $node)) {
                $addVpn($node);
            }

            foreach ($node as $k => &$v) {
                if (is_array($v)) {
                    if ($matchClient($k, $v)) {
                        $addVpn($v);
                    } else {
                        $walk($v, $k);
                    }
                }
            }
        };

        $walk($data);

        if ($changed) {
            $tmp = $file . ".tmp";
            file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            rename($tmp, $file);
            @chown($file, "www-data");
            @chgrp($file, "www-data");

            logx("AUTO_ADD_SUCCESS client=$client vpn=$vpnName");
        } else {
            logx("AUTO_ADD_NO_CHANGE client=$client vpn=$vpnName");
        }

        // save target map para alam CHR1/CHR2 target
        if ($domain !== "") {
            $targetFile = $DATA_DIR . "/targets.json";
            $targets = [];

            if (file_exists($targetFile)) {
                $t = json_decode(file_get_contents($targetFile), true);
                if (is_array($t)) $targets = $t;
            }

            $targets[$vpnName] = $domain;

            $tmpT = $targetFile . ".tmp";
            file_put_contents($tmpT, json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            rename($tmpT, $targetFile);
            @chown($targetFile, "www-data");
            @chgrp($targetFile, "www-data");
        }

        return $changed;

    } catch (Throwable $e) {
        logx("AUTO_ADD_ERROR " . $e->getMessage());
        return false;
    }
}


function http_post_json($url, $payload){
    // Direct PHP CLI wrapper para hindi na dumaan sa Nginx/HTTP
    $wrapper = __DIR__ . "/generate_vpn_cli_wrapper.php";

    if (!file_exists($wrapper)) {
        return [500, json_encode(["ok"=>false, "error"=>"generate_vpn_cli_wrapper.php missing"]), "wrapper missing"];
    }

    $u = trim((string)($payload["user"] ?? $payload["User"] ?? $payload["username"] ?? $payload["name"] ?? $payload["vpnName"] ?? ""));
    $p = trim((string)($payload["password"] ?? $payload["pass"] ?? ""));
    $profile = trim((string)($payload["profile"] ?? "1mbps"));

    if ($u !== "") {
        $payload["user"] = $u;
        $payload["User"] = $u;
        $payload["username"] = $u;
        $payload["name"] = $u;
        $payload["vpnName"] = $u;
    }

    if ($p !== "") {
        $payload["password"] = $p;
        $payload["pass"] = $p;
    }

    if ($profile === "") $profile = "1mbps";
    $payload["profile"] = $profile;

    $tmp = tempnam(sys_get_temp_dir(), "genvpn_");

    if (!$tmp) {
        return [500, json_encode(["ok"=>false, "error"=>"cannot create temp payload"]), "temp failed"];
    }

    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES));

    $cmd = "php " . escapeshellarg($wrapper) . " " . escapeshellarg($tmp) . " 2>&1";
    $raw = shell_exec($cmd);

    @unlink($tmp);

    if ($raw === null || trim((string)$raw) === "") {
        return [500, json_encode(["ok"=>false, "error"=>"empty wrapper response"]), "empty response"];
    }

    return [200, $raw, ""];
}


$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) $input = $_POST;

$client = clean_client_name_paid($input["client"] ?? "");
$user = trim((string)($input["user"] ?? ""));
$password = trim((string)($input["password"] ?? "21"));
$domain = trim((string)($input["domain"] ?? "vpn.marvincloud1.link"));

/* CLIENT PAID FORCE CHR2 MAINTENANCE FINAL */
$domain = "vpn.marvincloud2.link";
/* END CLIENT PAID FORCE CHR2 MAINTENANCE FINAL */


$profile = trim((string)($input["profile"] ?? "1mbps"));
$winboxPort = trim((string)($input["winboxPort"] ?? ""));
$winboxLocalPort = trim((string)($input["winboxLocalPort"] ?? "8291"));
$sshLocalPort = trim((string)($input["sshLocalPort"] ?? "22"));
$checkOnly = !empty($input["checkOnly"]);

if ($client === "") out(["ok"=>false, "message"=>"Missing client name"], 400);
if ($checkOnly) {
    $loc = find_client_balance_location($client);
    if (!$loc) out(["ok"=>false, "client"=>$client, "message"=>"Fund record not found"], 404);

    out([
        "ok"=>true,
        "client"=>$client,
        "balance"=>$loc["balance"],
        "file"=>basename($loc["file"]),
        "path"=>$loc["path"],
        "price"=>$GLOBALS["PRICE"]
    ]);
}

if ($user === "") out(["ok"=>false, "message"=>"Missing VPN name"], 400);

if (!in_array($domain, ["vpn.marvincloud1.link", "vpn.marvincloud2.link"], true)) {
    out(["ok"=>false, "message"=>"Invalid CHR target"], 400);
}

if ($password === "") $password = "21";
if ($profile === "") $profile = "1mbps";
if ($winboxLocalPort === "") $winboxLocalPort = "8291";
if ($sshLocalPort === "") $sshLocalPort = "22";

$lockFp = fopen($LOCK_FILE, "c+");
if (!$lockFp) out(["ok"=>false, "message"=>"Cannot open lock file"], 500);

flock($lockFp, LOCK_EX);

list($deductOk, $deductMsg, $deductInfo) = adjust_balance($client, -$PRICE);

if (!$deductOk) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    logx("INSUFFICIENT_OR_NOT_FOUND client=$client user=$user msg=$deductMsg info=" . json_encode($deductInfo));
    out([
        "ok"=>false,
        "message"=>$deductMsg,
        "client"=>$client,
        "debug"=>$deductInfo
    ], 402);
}

$payload = [
    "user" => $user,
    "password" => $password,
    "profile" => $profile,
    "domain" => $domain,
    "winboxPort" => $winboxPort,
    "winboxLocalPort" => $winboxLocalPort,
    "sshLocalPort" => $sshLocalPort
];

$payload = array_filter($payload, function($v){
    return $v !== null && $v !== "";
});

list($code, $raw, $err) = http_post_json("https://marvincloud.link/api/generate_vpn.php", $payload);
$gen = json_decode((string)$raw, true);

$hasError = false;
$success = false;
$msg = "";

if (is_array($gen)) {
    $msg = (string)($gen["message"] ?? "");
    $hasError = isset($gen["error"]) || (isset($gen["ok"]) && $gen["ok"] === false);

    $success = !$hasError && (
        (isset($gen["ok"]) && $gen["ok"] === true) ||
        stripos($msg, "generated") !== false ||
        stripos($msg, "pushed") !== false ||
        isset($gen["script"]) ||
        isset($gen["address"]) ||
        isset($gen["clientScript"])
    );
}

if (!$success) {
    adjust_balance($client, $PRICE);

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    logx("GENERATE_FAILED_REFUNDED client=$client user=$user raw=" . substr((string)$raw, 0, 500));

    out([
        "ok"=>false,
        "message"=>(
            $msg
            ?: (is_array($gen) && isset($gen["error"]) ? "Generate failed: " . $gen["error"] : "")
            ?: ($err ? "Generate request error: " . $err : "")
            ?: ((string)$raw !== "" ? "Generate raw error: " . substr(strip_tags((string)$raw), 0, 180) : "")
            ?: "Generate & Push failed. Fund refunded."
        ),
        "fundRefunded"=>true,
        "httpCode"=>$code,
        "error"=>$err,
        "generate"=>is_array($gen) ? $gen : null,
        "raw"=>is_array($gen) ? null : substr((string)$raw, 0, 500)
    ], 400);
}

@file_put_contents(
    $DATA_DIR . "/fund_transactions.jsonl",
    json_encode([
        "time"=>date("Y-m-d H:i:s"),
        "type"=>"client_generate_vpn",
        "client"=>$client,
        "vpnName"=>$user,
        "domain"=>$domain,
        "charge"=>$PRICE,
        "balanceBefore"=>$deductInfo["oldBalance"] ?? null,
        "balanceAfter"=>$deductInfo["newBalance"] ?? null,
        "file"=>$deductInfo["file"] ?? null,
        "path"=>$deductInfo["path"] ?? null
    ], JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
);

flock($lockFp, LOCK_UN);
fclose($lockFp);

logx("SUCCESS client=$client user=$user charge=$PRICE balance=" . ($deductInfo["newBalance"] ?? "") . " domain=$domain");

/* PAID AUTO ADD VPN TO CLIENT LIST FINAL CALL */
paid_auto_add_vpn_to_client_list_final($client, $user, $domain);

out([
    "ok"=>true,
    "message"=>"Generate & Push success. ₱300.00 deducted.",
    "price"=>$PRICE,
    "balance"=>$deductInfo["newBalance"] ?? null,
    "generate"=>$gen,
    "fundSource"=>[
        "file"=>$deductInfo["file"] ?? null,
        "path"=>$deductInfo["path"] ?? null
    ]
]);
