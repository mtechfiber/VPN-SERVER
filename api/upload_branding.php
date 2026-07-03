<?php
header('Content-Type: application/json');

$ADMIN_PASSWORD = '0oomarvin';

$root = realpath(__DIR__ . '/..');
$uploadDir = $root . '/uploads';
$faviconDir = $root . '/favicons';
$dataDir = $root . '/data';

function out_json($arr) {
    echo json_encode($arr);
    exit;
}

function run_cmd($cmd) {
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function patch_favicon_links($file, $version) {
    if (!file_exists($file)) return;

    $html = file_get_contents($file);

    $blocks = [
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

    foreach ($blocks as $b) {
        $html = preg_replace('/<!-- ' . preg_quote($b, '/') . ' START -->[\s\S]*?<!-- ' . preg_quote($b, '/') . ' END -->/i', '', $html);
    }

    $html = preg_replace('/<link[^>]+rel=["\'](?:shortcut icon|icon|apple-touch-icon|mask-icon|manifest)["\'][^>]*>\s*/i', '', $html);
    $html = preg_replace('/<meta[^>]+name=["\'](?:theme-color|apple-mobile-web-app-title|application-name)["\'][^>]*>\s*/i', '', $html);

    $block = "\n<!-- REAL UPLOAD BRANDING FAVICON START -->\n" .
        '<link rel="shortcut icon" type="image/x-icon" href="/favicons/brand-' . $version . '.ico">' . "\n" .
        '<link rel="icon" type="image/x-icon" href="/favicons/brand-' . $version . '.ico">' . "\n" .
        '<link rel="icon" type="image/png" sizes="512x512" href="/favicons/brand-' . $version . '.png">' . "\n" .
        '<link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-' . $version . '.png">' . "\n" .
        '<meta name="theme-color" content="#0d47a1">' . "\n" .
        '<meta name="apple-mobile-web-app-title" content="VPN Portal">' . "\n" .
        '<meta name="application-name" content="VPN Portal">' . "\n" .
        "<!-- REAL UPLOAD BRANDING FAVICON END -->\n";

    if (stripos($html, '<head>') !== false) {
        $html = preg_replace('/<head>/i', "<head>\n" . $block, $html, 1);
    } elseif (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $block . "\n</head>", $html, 1);
    } else {
        $html = $block . "\n" . $html;
    }

    file_put_contents($file, $html, LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out_json(['ok' => false, 'message' => 'POST only']);
}

$pass = $_POST['adminPassword'] ?? '';
if ($pass !== $ADMIN_PASSWORD) {
    out_json(['ok' => false, 'message' => 'Wrong admin password']);
}

if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    out_json(['ok' => false, 'message' => 'No file uploaded']);
}

if ($_FILES['logo']['size'] > 10 * 1024 * 1024) {
    out_json(['ok' => false, 'message' => 'Max 10MB only']);
}

@mkdir($uploadDir, 0755, true);
@mkdir($faviconDir, 0755, true);
@mkdir($dataDir, 0755, true);

$tmp = $_FILES['logo']['tmp_name'];
$name = $_FILES['logo']['name'] ?? 'logo.png';
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $tmp);
finfo_close($finfo);

$allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/svg+xml' => 'svg',
    'text/xml' => 'svg',
    'application/xml' => 'svg'
];

if (!isset($allowed[$mime]) && !in_array($ext, ['png','jpg','jpeg','webp','gif','svg'])) {
    out_json(['ok' => false, 'message' => 'Invalid file. Use PNG/JPG/WEBP/SVG']);
}

$ext = $allowed[$mime] ?? ($ext === 'jpeg' ? 'jpg' : $ext);
$version = time();

$original = $uploadDir . '/brand-original-' . $version . '.' . $ext;
$workPng = $uploadDir . '/brand-work-' . $version . '.png';
$logoPng = $uploadDir . '/portal-brand-logo.png';

if (!move_uploaded_file($tmp, $original)) {
    out_json(['ok' => false, 'message' => 'Upload failed']);
}

if ($ext === 'svg') {
    [$code, $error] = run_cmd('rsvg-convert -w 512 -h 512 ' . escapeshellarg($original) . ' -o ' . escapeshellarg($workPng));
    if ($code !== 0) {
        out_json(['ok' => false, 'message' => 'SVG convert failed', 'error' => $error]);
    }
} else {
    $workPng = $original;
}

/* Main login logo */
[$code, $error] = run_cmd(
    'convert ' . escapeshellarg($workPng) .
    ' -auto-orient -resize 512x512 -background white -gravity center -extent 512x512 ' .
    escapeshellarg($logoPng)
);

if ($code !== 0) {
    out_json(['ok' => false, 'message' => 'Logo convert failed', 'error' => $error]);
}

/* Favicon unique files */
$favPng = $faviconDir . '/brand-' . $version . '.png';
$favIco = $faviconDir . '/brand-' . $version . '.ico';
$apple = $faviconDir . '/apple-touch-' . $version . '.png';

run_cmd('convert ' . escapeshellarg($logoPng) . ' -resize 512x512 -background white -gravity center -extent 512x512 ' . escapeshellarg($favPng));
run_cmd('convert ' . escapeshellarg($logoPng) . ' -resize 180x180 -background white -gravity center -extent 180x180 ' . escapeshellarg($apple));

$tmp32 = '/tmp/favicon-brand-' . $version . '-32.png';
$tmp48 = '/tmp/favicon-brand-' . $version . '-48.png';

run_cmd('convert ' . escapeshellarg($logoPng) . ' -resize 32x32 -background white -gravity center -extent 32x32 ' . escapeshellarg($tmp32));
run_cmd('convert ' . escapeshellarg($logoPng) . ' -resize 48x48 -background white -gravity center -extent 48x48 ' . escapeshellarg($tmp48));
run_cmd('convert ' . escapeshellarg($tmp32) . ' ' . escapeshellarg($tmp48) . ' ' . escapeshellarg($favIco));

/* Root fallback */
@copy($favPng, $root . '/favicon.png');
@copy($favIco, $root . '/favicon.ico');
@copy($apple, $root . '/apple-touch-icon.png');

@chmod($logoPng, 0644);
@chmod($favPng, 0644);
@chmod($favIco, 0644);
@chmod($apple, 0644);
@chmod($root . '/favicon.png', 0644);
@chmod($root . '/favicon.ico', 0644);
@chmod($root . '/apple-touch-icon.png', 0644);

/* Patch all pages */
$pages = [
    $root . '/index.html',
    $root . '/admin/index.html',
    $root . '/client.html',
    $root . '/client2.html'
];

foreach ($pages as $page) {
    patch_favicon_links($page, $version);
}

$branding = [
    'ok' => true,
    'version' => $version,
    'logo' => '/uploads/portal-brand-logo.png?v=' . $version,
    'favicon_png' => '/favicons/brand-' . $version . '.png',
    'favicon_ico' => '/favicons/brand-' . $version . '.ico',
    'apple_touch' => '/favicons/apple-touch-' . $version . '.png',
    'uploaded' => date('Y-m-d H:i:s')
];

file_put_contents($dataDir . '/branding.json', json_encode($branding, JSON_PRETTY_PRINT), LOCK_EX);
@chmod($dataDir . '/branding.json', 0644);

out_json([
    'ok' => true,
    'message' => 'Logo and favicon updated',
    'version' => $version,
    'logo' => $branding['logo'],
    'favicon_png' => $branding['favicon_png'],
    'favicon_ico' => $branding['favicon_ico']
]);
