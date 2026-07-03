<?php
header('Content-Type: application/json');

class RouterosAPI {
    public $socket;
    public $timeout = 8;

    private function encodeLength($length) {
        if ($length < 0x80) return chr($length);
        if ($length < 0x4000) return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        if ($length < 0x200000) return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        if ($length < 0x10000000) return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    private function readLength() {
        $read = fread($this->socket, 1);
        if ($read === '' || $read === false) return 0;

        $c = ord($read);

        if (($c & 0x80) == 0x00) return $c;
        if (($c & 0xC0) == 0x80) return (($c & ~0xC0) << 8) + ord(fread($this->socket, 1));
        if (($c & 0xE0) == 0xC0) return (($c & ~0xE0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        if (($c & 0xF0) == 0xE0) return (($c & ~0xF0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));

        return (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
    }

    public function connect($ip, $login, $password, $port = 8728) {
        $this->socket = @fsockopen($ip, $port, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            throw new Exception("CHR API connect failed: $errstr");
        }

        stream_set_timeout($this->socket, $this->timeout);

        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=password=' . $password, true);

        $response = $this->read();

        foreach ($response as $r) {
            if (isset($r['!trap'])) {
                throw new Exception("CHR API login failed");
            }
        }

        return true;
    }

    public function write($word, $last = true) {
        fwrite($this->socket, $this->encodeLength(strlen($word)) . $word);
        if ($last) fwrite($this->socket, chr(0));
        return true;
    }

    public function read() {
        $response = [];
        $current = [];

        while (true) {
            $len = $this->readLength();

            if ($len === 0) {
                if (!empty($current)) {
                    $response[] = $current;
                    $current = [];
                }
                continue;
            }

            $word = fread($this->socket, $len);

            if ($word === '!done') {
                if (!empty($current)) $response[] = $current;
                break;
            }

            if ($word === '!re' || $word === '!trap' || $word === '!fatal') {
                if (!empty($current)) $response[] = $current;
                $current = [$word => true];
                continue;
            }

            if (substr($word, 0, 1) === '=') {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) $current[$parts[0]] = $parts[1];
            }
        }

        return $response;
    }

    public function comm($command, $params = []) {
        $this->write($command, false);

        foreach ($params as $k => $v) {
            $this->write('=' . $k . '=' . $v, false);
        }

        $this->write('', true);
        return $this->read();
    }

    public function disconnect() {
        if ($this->socket) fclose($this->socket);
    }
}

function out_json($arr) {
    echo json_encode($arr);
    exit;
}

function norm_port($p) {
    $p = trim((string)$p);
    if ($p === '' || !preg_match('/^[0-9]+$/', $p)) return '';
    $n = (int)$p;
    if ($n < 1 || $n > 65535) return '';
    return (string)$n;
}

function pick_key($arr, $keys) {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') {
            return trim((string)$arr[$k]);
        }
    }

    return '';
}

function walk_arrays($data, &$out = []) {
    if (!is_array($data)) return $out;

    $hasStrings = false;

    foreach ($data as $v) {
        if (is_string($v) || is_numeric($v)) {
            $hasStrings = true;
            break;
        }
    }

    if ($hasStrings) {
        $out[] = $data;
    }

    foreach ($data as $v) {
        if (is_array($v)) {
            walk_arrays($v, $out);
        }
    }

    return $out;
}

function extract_from_array($arr, $domain, $ip) {
    $blob = strtolower(json_encode($arr));

    if ($domain !== '' && strpos($blob, strtolower($domain)) === false && strpos($blob, strtolower($ip)) === false) {
        return null;
    }

    $host = pick_key($arr, ['host','ip','address','api_host','apiHost','router_ip','routerIp','server_ip','serverIp']);
    $user = pick_key($arr, ['user','username','api_user','apiUser','apiUsername','router_user','routerUser','mtUser','rosUser','login']);
    $pass = pick_key($arr, ['pass','password','api_pass','apiPass','apiPassword','router_pass','routerPass','mtPass','rosPass']);
    $port = pick_key($arr, ['api_port','apiPort','port']);

    if ($host === '') $host = $ip;
    if ($port === '') $port = '8728';

    if ($host !== '' && $user !== '' && $pass !== '') {
        return [
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'port' => (int)$port
        ];
    }

    return null;
}

function extract_from_php_code($domain, $ip) {
    $files = [
        __DIR__ . '/generate_vpn.php',
        __DIR__ . '/targets_store.php',
        __DIR__ . '/../data/targets.json'
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) continue;

        $code = file_get_contents($file);

        if (strpos($code, $domain) === false && strpos($code, $ip) === false) {
            continue;
        }

        $user = '';
        $pass = '';
        $host = $ip;
        $port = '8728';

        $pairs = [
            'user' => '/[\'"](?:user|username|api_user|apiUser|apiUsername|routerUser|login)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i',
            'pass' => '/[\'"](?:pass|password|api_pass|apiPass|apiPassword|routerPass)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i',
            'host' => '/[\'"](?:host|ip|address|apiHost|routerIp)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i',
            'port' => '/[\'"](?:api_port|apiPort|port)[\'"]\s*=>\s*[\'"]?([0-9]+)[\'"]?/i'
        ];

        if (preg_match($pairs['user'], $code, $m)) $user = $m[1];
        if (preg_match($pairs['pass'], $code, $m)) $pass = $m[1];
        if (preg_match($pairs['host'], $code, $m)) $host = $m[1];
        if (preg_match($pairs['port'], $code, $m)) $port = $m[1];

        if ($user !== '' && $pass !== '') {
            return [
                'host' => $host,
                'user' => $user,
                'pass' => $pass,
                'port' => (int)$port
            ];
        }
    }

    return null;
}

function find_chr_target($domain) {
    $hosts = [
        'vpn.marvincloud1.link' => '165.245.190.162',
        'vpn.marvincloud2.link' => '152.42.226.151'
    ];

    $domain = strtolower(trim($domain));
    $ip = $hosts[$domain] ?? '';

    if ($ip === '') return null;

    $jsonFiles = [
        __DIR__ . '/../data/targets.json',
        __DIR__ . '/../data/servers.json',
        __DIR__ . '/../data/chr_api.json'
    ];

    foreach ($jsonFiles as $file) {
        if (!file_exists($file)) continue;

        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) continue;

        $arrays = [];
        walk_arrays($json, $arrays);

        foreach ($arrays as $arr) {
            $target = extract_from_array($arr, $domain, $ip);
            if ($target) return $target;
        }
    }

    $fromPhp = extract_from_php_code($domain, $ip);
    if ($fromPhp) return $fromPhp;

    return null;
}

function update_local_json($client, $winboxPort, $sshDstPort, $winboxLocalPort, $sshLocalPort) {
    $file = realpath(__DIR__ . '/../data') . '/local_ports.json';

    $data = [];

    if (file_exists($file)) {
        $tmp = json_decode(file_get_contents($file), true);
        if (is_array($tmp)) $data = $tmp;
    }

    if (!isset($data['clients']) || !is_array($data['clients'])) $data['clients'] = [];
    if (!isset($data['_by_winbox_dst']) || !is_array($data['_by_winbox_dst'])) $data['_by_winbox_dst'] = [];

    $ck = strtolower(trim($client));

    if (!isset($data['clients'][$ck]) || !is_array($data['clients'][$ck])) {
        $data['clients'][$ck] = [];
    }

    $item = [
        'client' => $client,
        'winboxDstPort' => $winboxPort,
        'sshDstPort' => $sshDstPort,
        'winboxLocalPort' => $winboxLocalPort,
        'sshLocalPort' => $sshLocalPort,
        'winboxToPorts' => $winboxLocalPort,
        'sshToPorts' => $sshLocalPort,
        'updated' => date('Y-m-d H:i:s')
    ];

    $data['clients'][$ck][$winboxPort] = $item;
    $data['_by_winbox_dst'][$winboxPort] = $item;

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($file, 0664);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$client = trim((string)($input['client'] ?? ''));
$domain = strtolower(trim((string)($input['domain'] ?? '')));
$winboxPort = norm_port($input['winboxPort'] ?? '');
$winboxLocalPort = norm_port($input['winboxLocalPort'] ?? '');
$sshLocalPort = norm_port($input['sshLocalPort'] ?? '');

if ($client === '') out_json(['ok' => false, 'message' => 'Missing client']);
if ($domain === '') out_json(['ok' => false, 'message' => 'Missing domain']);
if ($winboxPort === '') out_json(['ok' => false, 'message' => 'Missing Winbox dst-port']);
if ($winboxLocalPort === '') out_json(['ok' => false, 'message' => 'Invalid Winbox local port']);
if ($sshLocalPort === '') out_json(['ok' => false, 'message' => 'Invalid SSH local port']);

$target = find_chr_target($domain);

if (!$target) {
    out_json([
        'ok' => false,
        'message' => 'CHR API credentials not found. Check /data/targets.json or /data/chr_api.json'
    ]);
}

$sshDstPort = (string)((int)$winboxPort + 1);

try {
    $api = new RouterosAPI();
    $api->connect($target['host'], $target['user'], $target['pass'], $target['port']);

    $nat = $api->comm('/ip/firewall/nat/print');

    $winboxId = '';
    $sshId = '';

    foreach ($nat as $row) {
        $id = (string)($row['.id'] ?? '');
        $dst = trim((string)($row['dst-port'] ?? ''));

        if ($dst === $winboxPort) $winboxId = $id;
        if ($dst === $sshDstPort) $sshId = $id;
    }

    if ($winboxId === '') throw new Exception("Winbox NAT dst-port $winboxPort not found");
    if ($sshId === '') throw new Exception("SSH NAT dst-port $sshDstPort not found");

    $api->comm('/ip/firewall/nat/set', [
        '.id' => $winboxId,
        'to-ports' => $winboxLocalPort
    ]);

    $api->comm('/ip/firewall/nat/set', [
        '.id' => $sshId,
        'to-ports' => $sshLocalPort
    ]);

    $api->disconnect();

    update_local_json($client, $winboxPort, $sshDstPort, $winboxLocalPort, $sshLocalPort);

    @mkdir(__DIR__ . '/../logs', 0775, true);
    @file_put_contents(
        __DIR__ . '/../logs/local_port_update.log',
        date('Y-m-d H:i:s') . " UPDATE client={$client} domain={$domain} winboxDst={$winboxPort} winboxLocal={$winboxLocalPort} sshDst={$sshDstPort} sshLocal={$sshLocalPort}\n",
        FILE_APPEND
    );

    out_json([
        'ok' => true,
        'message' => 'Local ports updated to CHR NAT',
        'domain' => $domain,
        'winboxDstPort' => $winboxPort,
        'sshDstPort' => $sshDstPort,
        'winboxLocalPort' => $winboxLocalPort,
        'sshLocalPort' => $sshLocalPort
    ]);

} catch (Throwable $e) {
    out_json([
        'ok' => false,
        'message' => $e->getMessage()
    ]);
}
