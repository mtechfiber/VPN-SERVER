<?php
header('Content-Type: application/json');

$ADMIN_PASSWORD = '0oomarvin';

$pass = $_POST['adminPassword'] ?? $_GET['adminPassword'] ?? '';
if ($pass !== $ADMIN_PASSWORD) {
    echo json_encode(['ok' => false, 'message' => 'Wrong admin password']);
    exit;
}

$root = realpath(__DIR__ . '/..');
$favDir = $root . '/favicons';
$dataDir = $root . '/data';

@mkdir($favDir, 0775, true);
@mkdir($dataDir, 0775, true);

$src = null;

if (file_exists($root . '/uploads/portal-brand-logo.png')) {
    $src = $root . '/uploads/portal-brand-logo.png';
} elseif (file_exists($root . '/uploads/vpn-logo.png')) {
    $src = $root . '/uploads/vpn-logo.png';
}

if (!$src || !file_exists($src)) {
    echo json_encode(['ok' => false, 'message' => 'No logo source found']);
    exit;
}

$version = time();

$favPng = $favDir . '/brand-' . $version . '.png';
$favIco = $favDir . '/brand-' . $version . '.ico';
$apple = $favDir . '/apple-touch-' . $version . '.png';

function run_cmd($cmd) {
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function patch_page($file, $version) {
    if (!file_exists($file) || !is_writable($file)) {
        return false;
    }

    $html = file_get_contents($file);

    $html = preg_replace('/<!-- REAL BRAND FAVICON SYNC START -->[\s\S]*?<!-- REAL BRAND FAVICON SYNC END -->/i', '', $html);

    $oldBlocks = [
        'REAL UPLOAD BRANDING FAVICON',
        'MCFG NEW FAVICON',
        'MCFG FAVICON SELF CONTAINED FINAL',
        'SAFARI FORCE FAVICON UPDATE',
        'CHROME SAFARI FAVICON TRIGGER',
        'LOGIN PAGE FAVICON FIX',
        'CLEAN FAVICON FINAL',
        'HARD FAVICON FINAL',
        'PORTAL FAVICON FINAL',
        'VPN LOGO FAVICON FINAL'
    ];

    foreach ($oldBlocks as $b) {
        $html = preg_replace('/<!-- ' . preg_quote($b, '/') . ' START -->[\s\S]*?<!-- ' . preg_quote($b, '/') . ' END -->/i', '', $html);
    }

    $html = preg_replace('/<link[^>]+rel=["\'](?:shortcut icon|icon|apple-touch-icon|mask-icon|manifest)["\'][^>]*>\s*/i', '', $html);
    $html = preg_replace('/<meta[^>]+name=["\'](?:theme-color|apple-mobile-web-app-title|application-name)["\'][^>]*>\s*/i', '', $html);

    $block = "\n<!-- REAL BRAND FAVICON SYNC START -->\n" .
        '<link rel="shortcut icon" type="image/x-icon" href="/favicons/brand-' . $version . '.ico">' . "\n" .
        '<link rel="icon" type="image/x-icon" href="/favicons/brand-' . $version . '.ico">' . "\n" .
        '<link rel="icon" type="image/png" sizes="512x512" href="/favicons/brand-' . $version . '.png">' . "\n" .
        '<link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-' . $version . '.png">' . "\n" .
        '<meta name="theme-color" content="#0d47a1">' . "\n" .
        '<meta name="apple-mobile-web-app-title" content="VPN Portal">' . "\n" .
        '<meta name="application-name" content="VPN Portal">' . "\n" .
        "<!-- REAL BRAND FAVICON SYNC END -->\n";

    if (stripos($html, '<head>') !== false) {
        $html = preg_replace('/<head>/i', "<head>\n" . $block, $html, 1);
    } elseif (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $block . "\n</head>", $html, 1);
    } else {
        $html = $block . "\n" . $html;
    }

    file_put_contents($file, $html, LOCK_EX);
    return true;
}

[$code1, $out1] = run_cmd('/usr/bin/convert ' . escapeshellarg($src) . ' -auto-orient -resize 512x512 -background white -gravity center -extent 512x512 ' . escapeshellarg($favPng));
[$code2, $out2] = run_cmd('/usr/bin/convert ' . escapeshellarg($src) . ' -auto-orient -resize 180x180 -background white -gravity center -extent 180x180 ' . escapeshellarg($apple));

$tmp32 = '/tmp/brand-fav-' . $version . '-32.png';
$tmp48 = '/tmp/brand-fav-' . $version . '-48.png';

run_cmd('/usr/bin/convert ' . escapeshellarg($src) . ' -auto-orient -resize 32x32 -background white -gravity center -extent 32x32 ' . escapeshellarg($tmp32));
run_cmd('/usr/bin/convert ' . escapeshellarg($src) . ' -auto-orient -resize 48x48 -background white -gravity center -extent 48x48 ' . escapeshellarg($tmp48));
[$code3, $out3] = run_cmd('/usr/bin/convert ' . escapeshellarg($tmp32) . ' ' . escapeshellarg($tmp48) . ' ' . escapeshellarg($favIco));

if ($code1 !== 0 || $code2 !== 0 || $code3 !== 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'Convert failed',
        'error' => $out1 . "\n" . $out2 . "\n" . $out3
    ]);
    exit;
}

@copy($favPng, $root . '/favicon.png');
@copy($favIco, $root . '/favicon.ico');
@copy($apple, $root . '/apple-touch-icon.png');

@chmod($favPng, 0644);
@chmod($favIco, 0644);
@chmod($apple, 0644);
@chmod($root . '/favicon.png', 0644);
@chmod($root . '/favicon.ico', 0644);
@chmod($root . '/apple-touch-icon.png', 0644);

$pages = [
    $root . '/index.html',
    $root . '/admin/index.html',
    $root . '/client.html',
    $root . '/client2.html'
];

$patched = [];
foreach ($pages as $p) {
    $patched[$p] = patch_page($p, $version);
}

$branding = [
    'ok' => true,
    'version' => $version,
    'source' => $src,
    'favicon_png' => '/favicons/brand-' . $version . '.png',
    'favicon_ico' => '/favicons/brand-' . $version . '.ico',
    'apple_touch' => '/favicons/apple-touch-' . $version . '.png',
    'patched' => $patched,
    'updated' => date('Y-m-d H:i:s')
];

file_put_contents($dataDir . '/branding.json', json_encode($branding, JSON_PRETTY_PRINT), LOCK_EX);
@chmod($dataDir . '/branding.json', 0644);

echo json_encode([
    'ok' => true,
    'message' => 'Favicon synced from current logo',
    'version' => $version,
    'favicon_png' => $branding['favicon_png'],
    'favicon_ico' => $branding['favicon_ico'],
    'patched' => $patched
]);
