<?php

declare(strict_types=1);

namespace Meetingax\Auth;

use Meetingax\Domain\AppException;
use Meetingax\Support\Tokens;
use PDO;

/**
 * Deltagarsession i en egen cookie. Token lagras bara som hash.
 * Sessionen skapas vid registrering och gäller även medan status är pending.
 */
final class ParticipantAuth
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
    ) {
    }

    public function current(): ?array
    {
        $token = $_COOKIE[$this->cookieName()] ?? '';
        if (!is_string($token) || strlen($token) !== 64 || ctype_xdigit($token) !== true) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.id AS session_id, s.expires_at,
                    p.id, p.public_id, p.meeting_id, p.name, p.email, p.status,
                    p.is_voting_eligible, p.last_seen_at,
                    m.public_id AS meeting_public_id, m.meeting_code,
                    m.title AS meeting_title, m.status AS meeting_status
             FROM participant_sessions s
             INNER JOIN participants p ON p.id = s.participant_id
             INNER JOIN meetings m ON m.id = p.meeting_id
             WHERE s.token_hash = ?'
        );
        $stmt->execute([Tokens::hash($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $expires = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $row['expires_at'],
            new \DateTimeZone('UTC')
        );
        if ($expires === false || $expires->getTimestamp() < time()) {
            $this->deleteSession((int) $row['session_id']);
            $this->clearCookie();
            return null;
        }
        if (in_array($row['status'], ['rejected', 'removed'], true)) {
            $this->deleteSessionsForParticipant((int) $row['id']);
            $this->clearCookie();
            return null;
        }
        $this->touch((int) $row['session_id']);
        return $row;
    }

    public function establish(string $token): void
    {
        $lifetime = (int) ($this->config['participant_cookie']['lifetime'] ?? 43200);
        setcookie($this->cookieName(), $token, [
            'expires' => time() + $lifetime,
            'path' => '/',
            'secure' => $this->secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function clearCookie(): void
    {
        setcookie($this->cookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function requireApi(): array
    {
        $participant = $this->current();
        if (!$participant) {
            throw new AppException('SESSION_ENDED', 'Din anmälan är inte längre aktiv.', 401);
        }
        return $participant;
    }

    public function deleteSessionsForParticipant(int $participantId): void
    {
        $this->pdo->prepare('DELETE FROM participant_sessions WHERE participant_id = ?')->execute([$participantId]);
    }

    private function deleteSession(int $sessionId): void
    {
        $this->pdo->prepare('DELETE FROM participant_sessions WHERE id = ?')->execute([$sessionId]);
    }

    private function touch(int $sessionId): void
    {
        $hours = max(1, min(48, intdiv((int) ($this->config['participant_cookie']['lifetime'] ?? 43200), 3600)));
        $this->pdo->prepare(
            'UPDATE participants p
             INNER JOIN participant_sessions s ON s.participant_id = p.id
             SET p.last_seen_at = UTC_TIMESTAMP(),
                 s.expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $hours . ' HOUR)
             WHERE s.id = ?
               AND (p.last_seen_at IS NULL OR p.last_seen_at < (UTC_TIMESTAMP() - INTERVAL 10 SECOND))'
        )->execute([$sessionId]);
    }

    private function cookieName(): string
    {
        return (string) ($this->config['participant_cookie']['name'] ?? 'MX_PARTICIPANT');
    }

    private function secure(): bool
    {
        return (bool) ($this->config['participant_cookie']['secure'] ?? false);
    }
}
