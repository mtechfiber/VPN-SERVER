<?php
header("Content-Type: application/json");

try {
    $config = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);

    $clientUser = trim($input["username"] ?? "");
    $password = trim($input["password"] ?? "");
    $vpnNames = $input["vpnNames"] ?? [];

    if ($clientUser === "" || $password === "" || !is_array($vpnNames) || count($vpnNames) === 0) {
        throw new Exception("Missing username, password, or VPN names");
    }

    $api = new RouterOSAPI();
    $api->connect(
        $config["host"],
        $config["user"],
        $config["pass"],
        $config["port"],
        $config["timeout"]
    );

    $results = [];

    foreach ($vpnNames as $vpnName) {
        $vpnName = trim($vpnName);

        if ($vpnName === "") {
            continue;
        }

        $existing = $api->comm("/ppp/secret/print", [
            "?name" => $vpnName
        ]);

        if (!empty($existing["re"])) {
            $id = $existing["re"][0][".id"];

            // Update password/service/profile only.
            // HINDI gagalawin ang comment para same pa rin sa CHR2.
            $api->comm("/ppp/secret/set", [
                ".id" => $id,
                "password" => $password,
                "service" => "sstp",
                "profile" => $config["profile"]
            ]);

            $results[] = [
                "vpnName" => $vpnName,
                "action" => "updated_comment_preserved"
            ];
        } else {
            // New secret default comment format.
            // Pwede mo palitan later sa CHR2 comment field.
            $defaultComment = $vpnName . " | WINBOX: 1517 | EXP: JUN 24, 2027";

            $api->comm("/ppp/secret/add", [
                "name" => $vpnName,
                "password" => $password,
                "service" => "sstp",
                "profile" => $config["profile"],
                "comment" => $defaultComment
            ]);

            $results[] = [
                "vpnName" => $vpnName,
                "action" => "created"
            ];
        }
    }

    $api->disconnect();

    echo json_encode([
        "ok" => true,
        "message" => "Client VPN account/s pushed to CHR2",
        "results" => $results
    ]);

} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
