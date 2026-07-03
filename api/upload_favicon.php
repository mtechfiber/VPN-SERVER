<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["ok" => false, "error" => "POST only"]);
    exit;
}

if (!isset($_FILES["favicon"])) {
    echo json_encode(["ok" => false, "error" => "No file uploaded"]);
    exit;
}

$file = $_FILES["favicon"];

if ($file["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["ok" => false, "error" => "Upload error"]);
    exit;
}

if ($file["size"] > 10 * 1024 * 1024) {
    echo json_encode(["ok" => false, "error" => "File too large. Max 10MB"]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file["tmp_name"]);
finfo_close($finfo);

$allowed = [
    "image/png" => "png",
    "image/jpeg" => "jpg",
    "image/webp" => "webp",
    "image/gif" => "gif"
];

if (!isset($allowed[$mime])) {
    echo json_encode(["ok" => false, "error" => "Only PNG, JPG, WEBP, GIF allowed"]);
    exit;
}

$ext = $allowed[$mime];
$name = "favicon-" . time() . "." . $ext;
$dest = __DIR__ . "/../uploads/" . $name;

if (!move_uploaded_file($file["tmp_name"], $dest)) {
    echo json_encode(["ok" => false, "error" => "Failed to save file"]);
    exit;
}

$href = "/uploads/" . $name;

$settingsFile = __DIR__ . "/../data/favicon.json";
file_put_contents($settingsFile, json_encode([
    "ok" => true,
    "href" => $href,
    "uploadedAt" => date("Y-m-d H:i:s")
], JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode([
    "ok" => true,
    "href" => $href,
    "message" => "Favicon uploaded"
]);
