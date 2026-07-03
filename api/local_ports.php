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

function fallback_json_map($winboxPort, $client, $domain, $source = 'fallback_default') {
    $file = realpath(__DIR__ . '/../data') . '/local_ports.json';

    $result = [
        'ok' => true,
        'source' => $source,
        'domain' => $domain,
        'winboxDstPort' => $winboxPort,
        'sshDstPort' => (string)((int)$winboxPort + 1),
        'winboxLocalPort' => '8291',
        'sshLocalPort' => '22'
    ];

    if (!file_exists($file)) return $result;

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return $result;

    if ($winboxPort !== '' && isset($data['_by_winbox_dst'][$winboxPort])) {
        $item = $data['_by_winbox_dst'][$winboxPort];

        $result['winboxLocalPort'] = (string)($item['winboxToPorts'] ?? $item['winboxLocalPort'] ?? '8291');
        $result['sshLocalPort'] = (string)($item['sshToPorts'] ?? $item['sshLocalPort'] ?? '22');
        $result['source'] = $source . '_json_map';

        return $result;
    }

    return $result;
}

$domain = strtolower(trim((string)($_GET['domain'] ?? '')));
$winboxPort = norm_port($_GET['winboxPort'] ?? $_GET['port'] ?? '');
$client = trim((string)($_GET['client'] ?? ''));

if ($winboxPort === '') {
    out_json([
        'ok' => false,
        'message' => 'Missing winboxPort',
        'winboxLocalPort' => '8291',
        'sshLocalPort' => '22'
    ]);
}

$sshDstPort = (string)((int)$winboxPort + 1);

$configFile = realpath(__DIR__ . '/../data') . '/chr_api.json';

if (!file_exists($configFile)) {
    out_json(fallback_json_map($winboxPort, $client, $domain, 'fallback_chr_api_json_missing'));
}

$config = json_decode(file_get_contents($configFile), true);

if (!is_array($config) || !isset($config[$domain])) {
    out_json(fallback_json_map($winboxPort, $client, $domain, 'fallback_domain_not_in_chr_api_json'));
}

$target = $config[$domain];

$host = trim((string)($target['host'] ?? ''));
$user = trim((string)($target['user'] ?? ''));
$pass = trim((string)($target['pass'] ?? ''));
$port = (int)($target['port'] ?? 8728);

if ($host === '' || $user === '' || $pass === '' || $user === 'CHR_API_USER' || $pass === 'CHR_API_PASSWORD') {
    out_json(fallback_json_map($winboxPort, $client, $domain, 'fallback_chr_api_credentials_empty_or_placeholder'));
}

try {
    $api = new RouterosAPI();
    $api->connect($host, $user, $pass, $port);

    $nat = $api->comm('/ip/firewall/nat/print');

    $winboxTo = '';
    $sshTo = '';

    foreach ($nat as $row) {
        $dst = trim((string)($row['dst-port'] ?? ''));
        $to = trim((string)($row['to-ports'] ?? ''));

        if ($dst === $winboxPort) {
            $winboxTo = $to;
        }

        if ($dst === $sshDstPort) {
            $sshTo = $to;
        }
    }

    $api->disconnect();

    
    
    if ($winboxTo === '') $winboxTo = '8291';
    if ($sshTo === '') $sshTo = '22';
out_json([
        'ok' => true,
        'source' => 'chr_live_nat',
        'domain' => $domain,
        'host' => $host,
        'winboxDstPort' => $winboxPort,
        'sshDstPort' => $sshDstPort,
        'winboxLocalPort' => $winboxTo,
        'sshLocalPort' => $sshTo
    ]);

} catch (Throwable $e) {
    $fallback = fallback_json_map($winboxPort, $client, $domain, 'fallback_chr_error');
    $fallback['error'] = $e->getMessage();
    out_json($fallback);
}
