<?php
function fund_file_path() {
    return realpath(__DIR__ . '/../data') . '/funds.json';
}

function fund_norm_client($name) {
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);
    return strtolower($name);
}

function fund_load_all() {
    $file = fund_file_path();

    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    $json = json_decode($raw, true);

    return is_array($json) ? $json : [];
}

function fund_save_all($data) {
    $file = fund_file_path();
    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($file, 0664);
}

function fund_money($n) {
    return number_format((float)$n, 2, '.', '');
}
