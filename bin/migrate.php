<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Meetingax\Database\Connection;
use Meetingax\Database\Migrator;

try {
    $config = meetingax_config();
} catch (RuntimeException) {
    fwrite(STDERR, "Saknar config/config.php. Kopiera config/config.example.php och fyll i databasen.\n");
    exit(1);
}

try {
    $pdo = Connection::make($config['db']);
    $applied = (new Migrator($pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, "Migrationen misslyckades.\n");
    error_log($e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
    exit(1);
}

if ($applied === []) {
    echo "Inga nya migrationer.\n";
    exit(0);
}

foreach ($applied as $version) {
    echo "Tillämpade {$version}\n";
}
