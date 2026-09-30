<?php

declare(strict_types=1);

namespace Meetingax\Auth;

use PDO;

/**
 * Enkel fönsterbaserad spärr. Identiteten är IP eller ett serverkänt id, aldrig ett värde från formuläret i klartext om det är ett lösenord.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($ip) || $ip === '' || strlen($ip) > 64) {
            return 'unknown';
        }
        return $ip;
    }

    public function allow(string $action, string $identity, int $max, int $windowSeconds): bool
    {
        $bucket = $action . '|' . $identity;
        if (strlen($bucket) > 190) {
            $bucket = $action . '|' . hash('sha256', $identity);
        }
        $windowStart = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_start, hit_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1'
        )->execute([$bucket, $windowStart]);
        $stmt = $this->pdo->prepare(
            'SELECT hit_count FROM rate_limits WHERE bucket_key = ? AND window_start = ?'
        );
        $stmt->execute([$bucket, $windowStart]);
        $count = (int) $stmt->fetchColumn();
        if (random_int(1, 40) === 1) {
            $this->pdo->exec('DELETE FROM rate_limits WHERE window_start < (UTC_TIMESTAMP() - INTERVAL 2 DAY)');
            $this->pdo->exec('DELETE FROM participant_sessions WHERE expires_at < UTC_TIMESTAMP()');
        }
        return $count <= $max;
    }
}
