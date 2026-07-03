<?php

/* MCFG GENERATE HIT LOGGER START */
@mkdir(__DIR__ . "/../logs", 0775, true);
@file_put_contents(
    __DIR__ . "/../logs/generate_hit.log",
    date("Y-m-d H:i:s") . " HIT generate_vpn.php METHOD=" . ($_SERVER["REQUEST_METHOD"] ?? "") . " URI=" . ($_SERVER["REQUEST_URI"] ?? "") . "
",
    FILE_APPEND
);
/* MCFG GENERATE HIT LOGGER END */

/* NO AUTO CLIENT LIST FROM GENERATOR START */
$__gen_clients_file = realpath(__DIR__ . '/../data/clients.json');
$__gen_clients_existed = false;
$__gen_clients_snapshot = null;

if ($__gen_clients_file && file_exists($__gen_clients_file)) {
    $__gen_clients_existed = true;
    $__gen_clients_snapshot = file_get_contents($__gen_clients_file);
}

register_shutdown_function(function() use ($__gen_clients_file, $__gen_clients_existed, $__gen_clients_snapshot) {
    if (!$__gen_clients_file) {
        return;
    }

    // Generate & Push should not create/update Admin Client List.
    // Restore clients.json after generator finishes.
    if ($__gen_clients_existed) {
        file_put_contents($__gen_clients_file, $__gen_clients_snapshot, LOCK_EX);
    } else {
        if (file_exists($__gen_clients_file)) {
            @unlink($__gen_clients_file);
        }
    }
});
/* NO AUTO CLIENT LIST FROM GENERATOR END */

date_default_timezone_set('Asia/Manila');
header("Content-Type: application/json");

function makeRemotePool($count = 300) {
    $pool = [];
    $third = 1;
    $fourth = 1;

    while (count($pool) < $count) {
        if ($fourth >= 255) {
            $third++;
            $fourth = 1;
            continue;
        }

        $pool[] = "16.16." . $third . "." . $fourth;
        $fourth++;
    }

    return $pool;
}

function randomUnusedRemote($api) {
    $used = [];

    $secrets = $api->comm("/ppp/secret/print");

    foreach ($secrets["re"] as $row) {
        if (!empty($row["remote-address"])) {
            $used[$row["remote-address"]] = true;
        }
    }

    $pool = makeRemotePool(300);
    shuffle($pool);

    foreach ($pool as $ip) {
        if (!isset($used[$ip])) {
            return $ip;
        }
    }

    throw new Exception("No available remote address from 300 IP pool");
}

function randomUnusedPort($api) {
    $used = [];

    $nat = $api->comm("/ip/firewall/nat/print");

    foreach ($nat["re"] as $row) {
        if (!empty($row["dst-port"])) {
            $ports = explode(",", $row["dst-port"]);

            foreach ($ports as $p) {
                $p = trim($p);

                if ($p !== "") {
                    $used[$p] = true;
                }
            }
        }
    }

    for ($i = 0; $i < 5000; $i++) {
        $port = (string)random_int(10000, 65535);

        if (!isset($used[$port])) {
            return $port;
        }
    }

    throw new Exception("No available random winbox port");
}

try {
    $base = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    
/* GENERATE VPN INPUT COMPAT FIX FINAL */
$__rawInputBody = file_get_contents("php://input");
$__jsonInput = json_decode($__rawInputBody, true);
$input = is_array($__jsonInput) ? $__jsonInput : [];

if (!empty($_POST) && is_array($_POST)) {
    $input = array_merge($input, $_POST);
}

if (!empty($_GET) && is_array($_GET)) {
    $input = array_merge($input, $_GET);
}

if ($__rawInputBody && empty($input)) {
    $__formInput = [];
    parse_str($__rawInputBody, $__formInput);
    if (is_array($__formInput)) {
        $input = array_merge($input, $__formInput);
    }
}

$__user = trim((string)($input["user"] ?? $input["User"] ?? $input["username"] ?? $input["name"] ?? $input["vpnName"] ?? ""));
$__pass = trim((string)($input["password"] ?? $input["Password"] ?? $input["pass"] ?? $input["vpnPass"] ?? ""));
$__domain = trim((string)($input["domain"] ?? $input["target"] ?? $input["chr"] ?? $input["server"] ?? ""));
$__winboxPort = trim((string)($input["winboxPort"] ?? $input["winbox_port"] ?? $input["dstPort"] ?? $input["port"] ?? ""));
$__profile = trim((string)($input["profile"] ?? $input["pppProfile"] ?? ""));
$__winboxLocalPort = trim((string)($input["winboxLocalPort"] ?? $input["winbox_local_port"] ?? ""));
$__sshLocalPort = trim((string)($input["sshLocalPort"] ?? $input["ssh_local_port"] ?? ""));

if ($__user !== "") {
    $input["user"] = $__user;
    $input["User"] = $__user;
    $_POST["user"] = $__user;
    $_REQUEST["user"] = $__user;
}

if ($__pass !== "") {
    $input["password"] = $__pass;
    $_POST["password"] = $__pass;
    $_REQUEST["password"] = $__pass;
}

if ($__domain !== "") {
    $input["domain"] = $__domain;
    $_POST["domain"] = $__domain;
    $_REQUEST["domain"] = $__domain;
}

if ($__winboxPort !== "") {
    $input["winboxPort"] = $__winboxPort;
    $_POST["winboxPort"] = $__winboxPort;
    $_REQUEST["winboxPort"] = $__winboxPort;
}

if ($__profile !== "") {
    $input["profile"] = $__profile;
    $_POST["profile"] = $__profile;
    $_REQUEST["profile"] = $__profile;
}

if ($__winboxLocalPort !== "") {
    $input["winboxLocalPort"] = $__winboxLocalPort;
    $_POST["winboxLocalPort"] = $__winboxLocalPort;
    $_REQUEST["winboxLocalPort"] = $__winboxLocalPort;
}

if ($__sshLocalPort !== "") {
    $input["sshLocalPort"] = $__sshLocalPort;
    $_POST["sshLocalPort"] = $__sshLocalPort;
    $_REQUEST["sshLocalPort"] = $__sshLocalPort;
}
/* END GENERATE VPN INPUT COMPAT FIX FINAL */


    $chr = trim($input["chr"] ?? "chr2");
    $service = strtolower(trim($input["service"] ?? "sstp"));
    $user = trim($input["user"] ?? "");
    $password = trim($input["password"] ?? "1");
    $profile = trim($input["profile"] ?? "1mbps");
    $remoteAddress = trim($input["remoteAddress"] ?? "");
    $winboxPort = trim($input["winboxPort"] ?? "");
    $exp = trim($input["exp"] ?? "");
    if ($exp === "") {
        $expDate = new DateTime("now", new DateTimeZone("Asia/Manila"));
        $expDate->modify("+1 year");
        $exp = strtoupper($expDate->format("M d, Y"));
    }

    $servers = [
        "chr1" => [
            "host" => "165.245.190.162",
            "dst_address" => "165.245.190.162",
            "domain" => "vpn.marvincloud1.link"
        ],
        "chr2" => [
            "host" => "152.42.226.151",
            "dst_address" => "152.42.226.151",
            "domain" => "vpn.marvincloud2.link"
        ]
    ];

    if (!isset($servers[$chr])) {
        throw new Exception("Invalid CHR selected");
    }

    if ($service !== "sstp" && $service !== "l2tp") {
        throw new Exception("VPN type must be sstp or l2tp");
    }

    if ($user === "" || $password === "" || $profile === "") {
        throw new Exception("Please fill User");
    }

    $server = $servers[$chr];

    $api = new RouterOSAPI();
    $api->connect(
        $server["host"],
        $base["user"],
        $base["pass"],
        8728,
        $base["timeout"] ?? 5
    );

    if ($remoteAddress === "" || strtolower($remoteAddress) === "random" || strtolower($remoteAddress) === "auto") {
        $remoteAddress = randomUnusedRemote($api);
    }

    if ($winboxPort === "" || strtolower($winboxPort) === "random" || strtolower($winboxPort) === "auto") {
        $winboxPort = randomUnusedPort($api);
    }

    if (!filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        throw new Exception("Invalid remote address");
    }

    if (!ctype_digit($winboxPort)) {
        throw new Exception("Winbox port must be number only");
    }

    $portNum = (int)$winboxPort;

    if ($portNum < 1 || $portNum > 65535) {
        throw new Exception("Winbox port must be 1-65535");
    }

    $comment = $user . " | WINBOX: " . $winboxPort . " | EXP: " . $exp;

    /* MCFG SAVE NAT TOPORTS MAP START */
    // Save NAT to-ports mapping for Client Portal modal
    if (!isset($winboxLocalPort) || trim((string)$winboxLocalPort) === "") {
        $winboxLocalPort = "8291";
    }

    if (!isset($sshLocalPort) || trim((string)$sshLocalPort) === "") {
        $sshLocalPort = "22";
    }

    $sshDstPortForMap = (string)(((int)$winboxPort) + 1);

    @mkdir(__DIR__ . "/../data", 0775, true);

    $localPortFile = __DIR__ . "/../data/local_ports.json";
    $localPortData = [];

    if (file_exists($localPortFile)) {
        $tmpLocalJson = json_decode(file_get_contents($localPortFile), true);
        if (is_array($tmpLocalJson)) {
            $localPortData = $tmpLocalJson;
        }
    }

    if (!isset($localPortData["clients"]) || !is_array($localPortData["clients"])) {
        $localPortData["clients"] = [];
    }

    if (!isset($localPortData["_by_winbox_dst"]) || !is_array($localPortData["_by_winbox_dst"])) {
        $localPortData["_by_winbox_dst"] = [];
    }

    $clientKeyForLocalPort = strtolower(trim($user));
    $winboxDstKey = (string)((int)$winboxPort);

    $localItem = [
        "client" => $user,
        "winboxDstPort" => $winboxPort,
        "sshDstPort" => $sshDstPortForMap,
        "winboxLocalPort" => (string)$winboxLocalPort,
        "sshLocalPort" => (string)$sshLocalPort,
        "winboxToPorts" => (string)$winboxLocalPort,
        "sshToPorts" => (string)$sshLocalPort,
        "remoteAddress" => $remoteAddress,
        "updated" => date("Y-m-d H:i:s")
    ];

    if (!isset($localPortData["clients"][$clientKeyForLocalPort]) || !is_array($localPortData["clients"][$clientKeyForLocalPort])) {
        $localPortData["clients"][$clientKeyForLocalPort] = [];
    }

    $localPortData["clients"][$clientKeyForLocalPort][$winboxDstKey] = $localItem;
    $localPortData["_by_winbox_dst"][$winboxDstKey] = $localItem;

    file_put_contents($localPortFile, json_encode($localPortData, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($localPortFile, 0664);

    @mkdir(__DIR__ . "/../logs", 0775, true);
    @file_put_contents(
        __DIR__ . "/../logs/generate_vpn.log",
        date("Y-m-d H:i:s") . " NAT TOPORTS MAP user={$user} dst={$winboxPort} winboxLocal={$winboxLocalPort} sshDst={$sshDstPortForMap} sshLocal={$sshLocalPort}\n",
        FILE_APPEND
    );
    /* MCFG SAVE NAT TOPORTS MAP END */



    /* MCFG CUSTOM LOCAL PORTS START */
    // Custom local ports for NAT to-ports
    // Defaults: Winbox local 8291, SSH local 22
    $winboxLocalPort = trim((string)(($data["winboxLocalPort"] ?? $_POST["winboxLocalPort"] ?? $_GET["winboxLocalPort"] ?? "8291")));
    $sshLocalPort = trim((string)(($data["sshLocalPort"] ?? $_POST["sshLocalPort"] ?? $_GET["sshLocalPort"] ?? "22")));

    if (!preg_match('/^[0-9]+$/', $winboxLocalPort) || (int)$winboxLocalPort < 1 || (int)$winboxLocalPort > 65535) {
        throw new Exception("Winbox local port must be 1-65535");
    }

    if (!preg_match('/^[0-9]+$/', $sshLocalPort) || (int)$sshLocalPort < 1 || (int)$sshLocalPort > 65535) {
        throw new Exception("SSH local port must be 1-65535");
    }

    $sshDstPort = (string)(((int)$winboxPort) + 1);

    // Save local port map for Client Portal modal
    @mkdir(__DIR__ . "/../data", 0775, true);
    $localPortFile = __DIR__ . "/../data/local_ports.json";
    $localPortData = [];

    if (file_exists($localPortFile)) {
        $tmpLocalJson = json_decode(file_get_contents($localPortFile), true);
        if (is_array($tmpLocalJson)) {
            $localPortData = $tmpLocalJson;
        }
    }

    if (!isset($localPortData["clients"]) || !is_array($localPortData["clients"])) {
        $localPortData["clients"] = [];
    }

    if (!isset($localPortData["_by_winbox_dst"]) || !is_array($localPortData["_by_winbox_dst"])) {
        $localPortData["_by_winbox_dst"] = [];
    }

    $clientKeyForLocalPort = strtolower(trim($user));
    $winboxDstKey = (string)((int)$winboxPort);

    $localItem = [
        "client" => $user,
        "winboxDstPort" => $winboxPort,
        "sshDstPort" => $sshDstPort,
        "winboxLocalPort" => $winboxLocalPort,
        "sshLocalPort" => $sshLocalPort,
        "remoteAddress" => $remoteAddress,
        "updated" => date("Y-m-d H:i:s")
    ];

    if (!isset($localPortData["clients"][$clientKeyForLocalPort]) || !is_array($localPortData["clients"][$clientKeyForLocalPort])) {
        $localPortData["clients"][$clientKeyForLocalPort] = [];
    }

    $localPortData["clients"][$clientKeyForLocalPort][$winboxDstKey] = $localItem;
    $localPortData["_by_winbox_dst"][$winboxDstKey] = $localItem;

    file_put_contents($localPortFile, json_encode($localPortData, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($localPortFile, 0664);
    /* MCFG CUSTOM LOCAL PORTS END */


    $winboxNatComment = $user . " | WINBOX";

    /* MCFG DIRECT INSTANT SSH NAT START */
    // Direct SSH NAT habang open pa ang MikroTik API connection
    // Winbox: dst-port = winboxPort     -> to-ports 8291
    // SSH:    dst-port = winboxPort + 1 -> to-ports 22
    $sshPort = (string)(((int)$winboxPort) + 1);

    if ((int)$sshPort > 65535) {
        throw new Exception("SSH port exceeds 65535. Choose lower Winbox port.");
    }

    $sshComment = $user . " | SSH";
    $sshFound = false;

    @mkdir(__DIR__ . "/../logs", 0775, true);
    @file_put_contents(
        __DIR__ . "/../logs/generate_vpn.log",
        date("Y-m-d H:i:s") . " SSH DIRECT START user={$user} winbox={$winboxPort} ssh={$sshPort} remote={$remoteAddress}\n",
        FILE_APPEND
    );

    $allNatForSsh = $api->comm("/ip/firewall/nat/print");

    foreach ($allNatForSsh as $row) {
        $rowId = (string)($row[".id"] ?? "");
        $rowComment = (string)($row["comment"] ?? "");
        $rowDstPort = trim((string)($row["dst-port"] ?? ""));
        $rowToPorts = trim((string)($row["to-ports"] ?? ""));
        $rowToAddress = trim((string)($row["to-addresses"] ?? ""));

        $sameClientSsh =
            ($rowComment === $sshComment) ||
            (stripos($rowComment, $user) !== false && stripos($rowComment, "SSH") !== false) ||
            ($rowDstPort === $sshPort && $rowToPorts === $sshLocalPort && $rowToAddress === $remoteAddress);

        if ($sameClientSsh && $rowId !== "") {
            $api->comm("/ip/firewall/nat/set", [
                ".id" => $rowId,
                "chain" => "dstnat",
                "dst-address" => $server["dst_address"],
                "dst-port" => $sshPort,
                "protocol" => "tcp",
                "action" => "dst-nat",
                "to-addresses" => $remoteAddress,
                "to-ports" => $sshLocalPort,
                "comment" => $sshComment
            ]);

            $sshFound = true;
            break;
        }

        if ($rowDstPort === $sshPort && !$sameClientSsh) {
            throw new Exception("SSH dst-port " . $sshPort . " already used by another NAT.");
        }
    }

    if (!$sshFound) {
        $api->comm("/ip/firewall/nat/add", [
            "chain" => "dstnat",
            "dst-address" => $server["dst_address"],
            "dst-port" => $sshPort,
            "protocol" => "tcp",
            "action" => "dst-nat",
            "to-addresses" => $remoteAddress,
            "to-ports" => $sshLocalPort,
            "comment" => $sshComment
        ]);
    }

    @file_put_contents(
        __DIR__ . "/../logs/generate_vpn.log",
        date("Y-m-d H:i:s") . " SSH DIRECT DONE user={$user} dst={$sshPort} to={$remoteAddress}:{$sshLocalPort}\n",
        FILE_APPEND
    );
    /* MCFG DIRECT INSTANT SSH NAT END */


$secretAction = "created";
    $natAction = "created";

    // Push PPP Secret sa selected CHR
    $existingSecret = $api->comm("/ppp/secret/print", [
        "?name" => $user
    ]);

    if (!empty($existingSecret["re"])) {
        $secretId = $existingSecret["re"][0][".id"];
$api->comm("/ppp/secret/set", [
            ".id" => $secretId,
            "password" => $password,
            "profile" => $profile,
            "service" => $service,
            "remote-address" => $remoteAddress,
            "comment" => $comment
        ]);

        $secretAction = "updated";
    } else {
        $api->comm("/ppp/secret/add", [
            "name" => $user,
            "password" => $password,
            "profile" => $profile,
            "service" => $service,
            "remote-address" => $remoteAddress,
            "comment" => $comment
        ]);
    }

    // Push NAT sa selected CHR
    $natId = "";
    $allNat = $api->comm("/ip/firewall/nat/print");

    foreach ($allNat["re"] as $nat) {
        $natComment = $nat["comment"] ?? "";

        if (strpos($natComment, $user . " | WINBOX:") === 0) {
            $natId = $nat[".id"];
            break;
        }
    }

    if ($natId !== "") {
        $api->comm("/ip/firewall/nat/set", [
            ".id" => $natId,
            "action" => "dst-nat",
            "chain" => "dstnat",
            "dst-address" => $server["dst_address"],
            "dst-port" => $winboxPort,
            "protocol" => "tcp",
            "to-addresses" => $remoteAddress,
            "to-ports" => $winboxLocalPort,
            "comment" => $winboxNatComment
        ]);

        $natAction = "updated";
    } else {
        $api->comm("/ip/firewall/nat/add", [
            "action" => "dst-nat",
            "chain" => "dstnat",
            "dst-address" => $server["dst_address"],
            "dst-port" => $winboxPort,
            "protocol" => "tcp",
            "to-addresses" => $remoteAddress,
            "to-ports" => $winboxLocalPort,
            "comment" => $winboxNatComment
        ]);
    }

    $api->disconnect();

    // Ito ang CHR script na automatic nang na-push sa CHR
    $chrScript = '/ppp secret
add name="' . $user . '" password="' . $password . '" profile=' . $profile . ' service=' . $service . ' remote-address=' . $remoteAddress . ' comment="' . $comment . '"

/ip firewall nat
add action=dst-nat chain=dstnat dst-address=' . $server["dst_address"] . ' dst-port=' . $winboxPort . ' protocol=tcp to-addresses=' . $remoteAddress . ' to-ports=8291 comment="' . $comment . '"';

    // Ito ang lalabas sa portal para sa client
    if ($service === "sstp") {
        $clientScript = '/interface sstp-client
add connect-to=' . $server["domain"] . ' disabled=no name="VPN_REMOTE" password=' . $password . ' profile=default-encryption user="' . $user . '" comment="' . $comment . '"

/ip firewall filter
add action=accept chain=input comment="ALLOW VPN REMOTE" in-interface="VPN_REMOTE"
add action=accept chain=forward comment="ALLOW VPN REMOTE" in-interface="VPN_REMOTE"';
    } else {
        $clientScript = '/interface l2tp-client
add connect-to=' . $server["domain"] . ' disabled=no name="VPN_REMOTE" password=' . $password . ' user="' . $user . '" use-ipsec=no comment="' . $comment . '"

/ip firewall filter
add action=accept chain=input comment="ALLOW VPN REMOTE" in-interface="VPN_REMOTE"
add action=accept chain=forward comment="ALLOW VPN REMOTE" in-interface="VPN_REMOTE"';
    }

    echo json_encode([
        "ok" => true,
        "message" => "Auto pushed to " . strtoupper($chr) . " and generated client script",
        "chr" => $chr,
        "address" => $server["domain"] . ":" . $winboxPort,
        "remoteAddress" => $remoteAddress,
        "winboxPort" => $winboxPort,
        "secretAction" => $secretAction,
        "natAction" => $natAction,
        "comment" => $comment,
        "chrScript" => $chrScript,
        "script" => $clientScript
    ]);

} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
