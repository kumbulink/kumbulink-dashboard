<?php

if (!defined('ABSPATH')) {
    exit;
}

function kumbulink_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }

    return false;
}

function kumbulink_is_localhost(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_contains($host, 'localhost:');
}
