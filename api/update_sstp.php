<?php
header("Content-Type: application/json");

try {
    $config = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);

    $clientUser = trim($input["username"] ?? "");
    $password = trim($input["password"] ?? "");
    $vpnNames = $input["vpnNames"] ?? [];
    $oldVpnNames = $input["oldVpnNames"] ?? [];

    if ($clientUser === "" || $password === "" || !is_array($vpnNames) || count($vpnNames) === 0) {
        throw new Exception("Missing username, password, or VPN names");
    }

    $vpnNames = array_values(array_filter(array_map("trim", $vpnNames)));
    $oldVpnNames = array_values(array_filter(array_map("trim", $oldVpnNames)));

    $api = new RouterOSAPI();
    $api->connect($config["host"], $config["user"], $config["pass"], $config["port"], $config["timeout"]);

    $created = [];
    $updated = [];
    $removed = [];

    foreach ($vpnNames as $vpnName) {
        $existing = $api->comm("/ppp/secret/print", [
            "?name" => $vpnName
        ]);

        if (!empty($existing["re"])) {
            $id = $existing["re"][0][".id"];

            $api->comm("/ppp/secret/set", [
                ".id" => $id,
                "password" => $password,
                "service" => "sstp",
                "profile" => $config["profile"],
                "comment" => "portal-user:" . $clientUser
            ]);

            $updated[] = $vpnName;
        } else {
            $api->comm("/ppp/secret/add", [
                "name" => $vpnName,
                "password" => $password,
                "service" => "sstp",
                "profile" => $config["profile"],
                "comment" => "portal-user:" . $clientUser
            ]);

            $created[] = $vpnName;
        }
    }

    foreach ($oldVpnNames as $oldName) {
        if (!in_array($oldName, $vpnNames, true)) {
            $existing = $api->comm("/ppp/secret/print", [
                "?name" => $oldName
            ]);

            if (!empty($existing["re"])) {
                $id = $existing["re"][0][".id"];

                $api->comm("/ppp/secret/remove", [
                    ".id" => $id
                ]);

                $removed[] = $oldName;
            }
        }
    }

    $api->disconnect();

    echo json_encode([
        "ok" => true,
        "message" => "Client updated to CHR2",
        "created" => $created,
        "updated" => $updated,
        "removed" => $removed
    ]);
} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
