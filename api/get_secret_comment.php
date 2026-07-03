<?php
header("Content-Type: application/json");

try {
    $base = require __DIR__ . "/config.php";
    require __DIR__ . "/routeros_api.php";

    $input = json_decode(file_get_contents("php://input"), true);

    $vpnName = trim($input["vpnName"] ?? "");
    $chr = trim($input["chr"] ?? "chr2");

    $servers = [
        "chr1" => [
            "host" => "165.245.190.162",
            "domain" => "vpn.marvincloud1.link"
        ],
        "chr2" => [
            "host" => "152.42.226.151",
            "domain" => "vpn.marvincloud2.link"
        ]
    ];

    if ($vpnName === "") {
        throw new Exception("Missing vpnName");
    }

    if (!isset($servers[$chr])) {
        $chr = "chr2";
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

    $comment = "";
    $port = "";
    $remoteAddress = "";

    $secret = $api->comm("/ppp/secret/print", [
        "?name" => $vpnName
    ]);

    if (!empty($secret["re"])) {
        $comment = $secret["re"][0]["comment"] ?? "";
        $remoteAddress = $secret["re"][0]["remote-address"] ?? "";
    }

    if ($comment !== "" && preg_match('/WINBOX:\s*([0-9]+)/i', $comment, $match)) {
        $port = $match[1];
    }

    if ($port === "") {
        $nat = $api->comm("/ip/firewall/nat/print");

        foreach ($nat["re"] as $row) {
            $natComment = $row["comment"] ?? "";
            $natToAddress = $row["to-addresses"] ?? "";
            $natPort = $row["dst-port"] ?? "";

            if (strpos($natComment, $vpnName . " | WINBOX:") === 0) {
                if ($natPort !== "") {
                    $port = $natPort;
                }

                if ($comment === "") {
                    $comment = $natComment;
                }

                break;
            }

            if ($remoteAddress !== "" && $natToAddress === $remoteAddress && $natPort !== "") {
                $port = $natPort;

                if ($comment === "" && $natComment !== "") {
                    $comment = $natComment;
                }

                break;
            }
        }
    }

    if ($port === "") {
        $port = "0000";
    }

    if ($comment === "") {
        $comment = $vpnName . " | WINBOX: " . $port . " | EXP: UNKNOWN";
    }

    $api->disconnect();

    echo json_encode([
        "ok" => true,
        "chr" => $chr,
        "domain" => $server["domain"],
        "vpnName" => $vpnName,
        "comment" => $comment,
        "port" => $port
    ]);

} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
