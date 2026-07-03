<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$ADMIN_PASSWORD = "0oomarvin";

$inputPassword = $_POST["adminPassword"] ?? "";

if ($inputPassword !== $ADMIN_PASSWORD) {
    echo json_encode(["ok" => false, "error" => "Invalid admin password"]);
    exit;
}

$root = realpath(__DIR__ . "/..");
$backupDir = $root . "/backups";

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$filename = "vpn-portal-backup-" . date("Y-m-d-His") . ".zip";
$zipPath = $backupDir . "/" . $filename;

$include = [
    "index.html",
    "client.html",
    "client2.html",
    "admin",
    "api",
    "data",
    "uploads",
    "favicon.ico",
    "favicon.png",
    "apple-touch-icon.png"
];

$excludeContains = [
    "/backups/",
    ".backup",
    ".broken",
    ".zip",
    "/logs/"
];

function shouldExclude($path, $excludeContains) {
    $path = str_replace("\\", "/", $path);

    foreach ($excludeContains as $bad) {
        if (stripos($path, $bad) !== false) {
            return true;
        }
    }

    return false;
}

function addPathToZip($zip, $baseDir, $path, $excludeContains) {
    $full = $baseDir . "/" . $path;

    if (!file_exists($full)) {
        return;
    }

    if (is_file($full)) {
        if (!shouldExclude($full, $excludeContains)) {
            $zip->addFile($full, $path);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $filePath = $file->getPathname();

        if (shouldExclude($filePath, $excludeContains)) {
            continue;
        }

        $localPath = substr($filePath, strlen($baseDir) + 1);
        $localPath = str_replace("\\", "/", $localPath);

        if ($file->isDir()) {
            $zip->addEmptyDir($localPath);
        } else {
            $zip->addFile($filePath, $localPath);
        }
    }
}

try {
    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception("Cannot create zip");
    }

    foreach ($include as $item) {
        addPathToZip($zip, $root, $item, $excludeContains);
    }

    $zip->close();

    chmod($zipPath, 0644);

    echo json_encode([
        "ok" => true,
        "file" => $filename,
        "href" => "/backups/" . $filename,
        "size" => filesize($zipPath),
        "message" => "Backup created"
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
