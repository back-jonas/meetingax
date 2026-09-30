<?php

declare(strict_types=1);

/**
 * Router för PHP:s inbyggda webbserver. Apache använder .htaccess i stället.
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . (is_string($path) ? $path : '');
if (is_string($path) && $path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
