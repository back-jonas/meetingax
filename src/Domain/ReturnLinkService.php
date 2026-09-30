<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use Meetingax\Support\Tokens;
use PDO;
use PDOException;

/**
 * Personlig länk tillbaka till samma anmälan. Råtoken lämnar bara servern i mejlet.
 */
final class ReturnLinkService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetimeSeconds = 604800,
    ) {
    }

    public function issue(int $participantId): string
    {
        $seconds = max(3600, min(86400 * 30, $this->lifetimeSeconds));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = Tokens::sessionToken();
            try {
                $this->pdo->prepare(
                    'INSERT INTO participant_return_tokens (participant_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $seconds . ' SECOND))'
                )->execute([$participantId, Tokens::hash($token)]);
                return $token;
            } catch (PDOException $e) {
                if (is_duplicate_key($e) && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
        throw new AppException('RETRY', 'Kunde inte skapa länken. Försök igen.');
    }

    /**
     * @return array{session_token: string, meeting_code: string, status: string}
     */
    public function redeem(string $token): array
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new AppException('NOT_FOUND', 'Länken är ogiltig eller har gått ut.', 404);
        }
        return db_transaction($this->pdo, function () use ($token) {
            $stmt = $this->pdo->prepare(
                'SELECT id, participant_id
                 FROM participant_return_tokens
                 WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $stmt->execute([Tokens::hash($token)]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new AppException('NOT_FOUND', 'Länken är ogiltig eller har gått ut.', 404);
            }
            $participant = $this->pdo->prepare(
                'SELECT p.id, p.name, p.status, p.meeting_id, m.meeting_code
                 FROM participants p
                 INNER JOIN meetings m ON m.id = p.meeting_id
                 WHERE p.id = ?
                 FOR UPDATE'
            );
            $participant->execute([(int) $row['participant_id']]);
            $person = $participant->fetch();
            if (!$person || in_array($person['status'], ['rejected', 'removed'], true)) {
                $this->revoke((int) $row['participant_id']);
                throw new AppException('NOT_FOUND', 'Länken är ogiltig eller har gått ut.', 404);
            }
            if (!Tokens::isMeetingCode((string) $person['meeting_code'])) {
                throw new AppException('NOT_FOUND', 'Länken är ogiltig eller har gått ut.', 404);
            }
            $sessionToken = (new ParticipantService($this->pdo))->openSession((int) $person['id']);
            $this->pdo->prepare(
                'UPDATE participant_return_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([(int) $row['id']]);
            (new AuditLog($this->pdo))->write((int) $person['meeting_id'], null, (int) $person['id'], 'RETURN_LINK_USED', [
                'name' => $person['name'],
            ]);
            return [
                'session_token' => $sessionToken,
                'meeting_code' => (string) $person['meeting_code'],
                'status' => (string) $person['status'],
            ];
        });
    }

    public function revoke(int $participantId): void
    {
        $this->pdo->prepare('DELETE FROM participant_return_tokens WHERE participant_id = ?')->execute([$participantId]);
    }
}
