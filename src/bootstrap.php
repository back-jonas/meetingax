<?php

declare(strict_types=1);

/**
 * Autoload och gemensamma hjälpfunktioner. Startar inte webbappen.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Meetingax\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Support/helpers.php';

function meetingax_config(): array
{
    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('CONFIG_MISSING');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('CONFIG_INVALID');
    }
    return $config;
}
