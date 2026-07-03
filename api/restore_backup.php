<?php
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$ADMIN_PASSWORD = "0oomarvin";

$inputPassword = $_POST["adminPassword"] ?? "";

if ($inputPassword !== $ADMIN_PASSWORD) {
    echo json_encode(["ok" => false, "error" => "Invalid admin password"]);
    exit;
}

if (!isset($_FILES["backup"])) {
    echo json_encode(["ok" => false, "error" => "No backup file uploaded"]);
    exit;
}

$file = $_FILES["backup"];

if ($file["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["ok" => false, "error" => "Upload failed"]);
    exit;
}

if ($file["size"] > 30 * 1024 * 1024) {
    echo json_encode(["ok" => false, "error" => "Backup too large. Max 30MB"]);
    exit;
}

$root = realpath(__DIR__ . "/..");
$backupDir = $root . "/backups";

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

function zipCurrentSite($root, $backupDir) {
    $filename = "pre-restore-backup-" . date("Y-m-d-His") . ".zip";
    $zipPath = $backupDir . "/" . $filename;

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $filePath = str_replace("\\", "/", $file->getPathname());

        if (stripos($filePath, "/backups/") !== false) {
            continue;
        }

        if (stripos($filePath, ".zip") !== false) {
            continue;
        }

        $localPath = substr($filePath, strlen($root) + 1);

        if ($file->isDir()) {
            $zip->addEmptyDir($localPath);
        } else {
            $zip->addFile($filePath, $localPath);
        }
    }

    $zip->close();
    chmod($zipPath, 0644);

    return "/backups/" . $filename;
}

function copyDirSafe($src, $dst) {
    $src = rtrim($src, "/");
    $dst = rtrim($dst, "/");

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $filePath = str_replace("\\", "/", $file->getPathname());
        $relative = substr($filePath, strlen($src) + 1);

        if ($relative === "") {
            continue;
        }

        if (stripos($relative, "backups/") === 0) {
            continue;
        }

        if (stripos($relative, ".zip") !== false) {
            continue;
        }

        $target = $dst . "/" . $relative;

        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            $parent = dirname($target);

            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }

            copy($filePath, $target);
            chmod($target, 0664);
        }
    }
}

function deleteDir($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

try {
    $tmpZip = $file["tmp_name"];

    $zip = new ZipArchive();

    if ($zip->open($tmpZip) !== true) {
        throw new Exception("Invalid zip file");
    }

    $extractDir = sys_get_temp_dir() . "/vpn-restore-" . time() . "-" . rand(1000, 9999);

    mkdir($extractDir, 0775, true);

    if (!$zip->extractTo($extractDir)) {
        throw new Exception("Failed to extract backup");
    }

    $zip->close();

    $sourceRoot = $extractDir;

    if (is_dir($extractDir . "/html")) {
        $sourceRoot = $extractDir . "/html";
    }

    $hasIndex = file_exists($sourceRoot . "/index.html");
    $hasAdmin = is_dir($sourceRoot . "/admin");
    $hasApi = is_dir($sourceRoot . "/api");

    if (!$hasIndex && !$hasAdmin && !$hasApi) {
        deleteDir($extractDir);
        throw new Exception("Backup content not recognized");
    }

    $preRestoreBackup = zipCurrentSite($root, $backupDir);

    copyDirSafe($sourceRoot, $root);

    deleteDir($extractDir);

    echo json_encode([
        "ok" => true,
        "message" => "Restore complete",
        "preRestoreBackup" => $preRestoreBackup
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "ok" => false,
        "error" => $e->getMessage()
    ]);
}
