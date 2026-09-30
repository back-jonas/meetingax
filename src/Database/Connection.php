<?php

declare(strict_types=1);

namespace Meetingax\Database;

use PDO;
use PDOException;

/**
 * Enda stället som öppnar en databasanslutning.
 */
final class Connection
{
    public static function make(array $config): PDO
    {
        try {
            $pdo = new PDO(
                (string) $config['dsn'],
                (string) $config['user'],
                (string) $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Databasanslutning misslyckades: ' . $e->getMessage());
            throw new PDOException('Databasanslutning misslyckades.', (int) $e->getCode());
        }
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
