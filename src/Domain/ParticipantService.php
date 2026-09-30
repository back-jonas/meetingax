<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use Meetingax\Support\Dates;
use Meetingax\Support\Tokens;
use Meetingax\Support\Validator;
use PDO;
use PDOException;

final class ParticipantService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $sessionLifetime = 43200,
    ) {
    }

    /**
     * Skapar deltagare, svar och session i samma transaktion.
     * Status är pending. Rösträtt ges inte automatiskt.
     *
     * @param array<int, string> $values
     * @return array{participant: array, token: string}
     */
    public function register(array $meeting, string $name, string $email, array $values): array
    {
        if ($meeting['status'] !== 'open') {
            throw new AppException('MEETING_NOT_OPEN', 'Mötet tar inte emot anmälningar.');
        }
        $nameError = Validator::name($name, 160, 'Namnet');
        if ($nameError !== null) {
            throw new AppException('VALIDATION', $nameError);
        }
        $emailError = Validator::email($email);
        if ($emailError !== null) {
            throw new AppException('VALIDATION', $emailError);
        }
        $name = trim($name);
        $email = Validator::normalizeEmail($email);

        return db_transaction($this->pdo, function () use ($meeting, $name, $email, $values) {
            $locked = (new MeetingService($this->pdo))->lock((int) $meeting['id']);
            if ($locked['status'] !== 'open') {
                throw new AppException('MEETING_NOT_OPEN', 'Mötet tar inte emot anmälningar.');
            }
            $participantId = $this->insertParticipant((int) $meeting['id'], $name, $email);
            (new FieldService($this->pdo))->saveValues((int) $meeting['id'], $participantId, $values);
            $token = Tokens::sessionToken();
            $hours = max(1, min(48, intdiv($this->sessionLifetime, 3600)));
            $this->pdo->prepare(
                'INSERT INTO participant_sessions (participant_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $hours . ' HOUR))'
            )->execute([$participantId, Tokens::hash($token)]);
            (new AuditLog($this->pdo))->write((int) $meeting['id'], null, $participantId, 'PARTICIPANT_REGISTERED', [
                'name' => $name,
            ]);
            return [
                'participant' => $this->mustFind($participantId, (int) $meeting['id']),
                'token' => $token,
            ];
        });
    }

    public function listForMeeting(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, name, email, status, is_voting_eligible,
                    registered_at, approved_at, last_seen_at
             FROM participants
             WHERE meeting_id = ?
             ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\', \'removed\'), registered_at, id'
        );
        $stmt->execute([$meetingId]);
        $rows = $stmt->fetchAll();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $fields = (new FieldService($this->pdo))->valuesByParticipant($ids);
        foreach ($rows as &$row) {
            $row['fields'] = $fields[(int) $row['id']] ?? [];
            $row['online'] = Dates::isOnline($row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null);
            $row['presence_label'] = Dates::presence($row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null);
        }
        unset($row);
        return $rows;
    }

    public function findInMeeting(int $meetingId, string $publicId): ?array
    {
        if (!Tokens::isPublicId($publicId)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, name, email, status, is_voting_eligible, registered_at, approved_at, last_seen_at
             FROM participants
             WHERE meeting_id = ? AND public_id = ?'
        );
        $stmt->execute([$meetingId, $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function counts(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n,
                    SUM(CASE WHEN is_voting_eligible = 1 AND status = \'approved\' THEN 1 ELSE 0 END) AS eligible
             FROM participants
             WHERE meeting_id = ?
             GROUP BY status'
        );
        $stmt->execute([$meetingId]);
        $counts = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'removed' => 0,
            'voting_eligible' => 0,
            'total' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
            $counts['voting_eligible'] += (int) $row['eligible'];
            $counts['total'] += (int) $row['n'];
        }
        return $counts;
    }

    public function approve(array $meeting, array $participant, int $userId): void
    {
        $this->change($meeting, $participant, $userId, function (array $locked) use ($userId): void {
            if ($locked['status'] !== 'pending') {
                throw new AppException('INVALID_STATE', 'Deltagaren kan inte godkännas i nuvarande status.');
            }
            $this->pdo->prepare(
                'UPDATE participants
                 SET status = ?, approved_at = UTC_TIMESTAMP(), approved_by_user_id = ?
                 WHERE id = ?'
            )->execute(['approved', $userId, $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $locked['meeting_id'], $userId, (int) $locked['id'], 'PARTICIPANT_APPROVED', [
                'name' => $locked['name'],
            ]);
        });
    }

    public function reject(array $meeting, array $participant, int $userId): void
    {
        $this->change($meeting, $participant, $userId, function (array $locked) use ($userId): void {
            if ($locked['status'] !== 'pending') {
                throw new AppException('INVALID_STATE', 'Deltagaren kan inte avslås i nuvarande status.');
            }
            $this->pdo->prepare(
                'UPDATE participants SET status = ?, is_voting_eligible = 0 WHERE id = ?'
            )->execute(['rejected', $locked['id']]);
            $this->pdo->prepare('DELETE FROM participant_sessions WHERE participant_id = ?')->execute([(int) $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $locked['meeting_id'], $userId, (int) $locked['id'], 'PARTICIPANT_REJECTED', [
                'name' => $locked['name'],
            ]);
        });
    }

    public function remove(array $meeting, array $participant, int $userId): void
    {
        $this->change($meeting, $participant, $userId, function (array $locked) use ($userId): void {
            if ($locked['status'] === 'removed') {
                throw new AppException('INVALID_STATE', 'Deltagaren är redan borttagen.');
            }
            $this->pdo->prepare(
                'UPDATE participants SET status = ?, is_voting_eligible = 0 WHERE id = ?'
            )->execute(['removed', $locked['id']]);
            $this->pdo->prepare('DELETE FROM participant_sessions WHERE participant_id = ?')->execute([(int) $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $locked['meeting_id'], $userId, (int) $locked['id'], 'PARTICIPANT_REMOVED', [
                'name' => $locked['name'],
            ]);
        });
    }

    public function grantVote(array $meeting, array $participant, int $userId): void
    {
        $this->change($meeting, $participant, $userId, function (array $locked) use ($userId): void {
            if ($locked['status'] !== 'approved') {
                throw new AppException('INVALID_STATE', 'Bara godkända deltagare kan få rösträtt.');
            }
            if ((int) $locked['is_voting_eligible'] === 1) {
                throw new AppException('INVALID_STATE', 'Deltagaren har redan rösträtt.');
            }
            $this->pdo->prepare('UPDATE participants SET is_voting_eligible = 1 WHERE id = ?')->execute([(int) $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $locked['meeting_id'], $userId, (int) $locked['id'], 'VOTING_RIGHT_GRANTED', [
                'name' => $locked['name'],
            ]);
        });
    }

    public function revokeVote(array $meeting, array $participant, int $userId): void
    {
        $this->change($meeting, $participant, $userId, function (array $locked) use ($userId): void {
            if ((int) $locked['is_voting_eligible'] !== 1) {
                throw new AppException('INVALID_STATE', 'Deltagaren har ingen rösträtt att ta bort.');
            }
            $this->pdo->prepare('UPDATE participants SET is_voting_eligible = 0 WHERE id = ?')->execute([(int) $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $locked['meeting_id'], $userId, (int) $locked['id'], 'VOTING_RIGHT_REVOKED', [
                'name' => $locked['name'],
            ]);
        });
    }

    private function change(array $meeting, array $participant, int $userId, callable $fn): void
    {
        if ($meeting['status'] === 'archived') {
            throw new AppException('INVALID_STATE', 'Arkiverade möten kan inte ändras.');
        }
        if ((int) $participant['meeting_id'] !== (int) $meeting['id']) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        db_transaction($this->pdo, function () use ($meeting, $participant, $fn) {
            $locked = $this->lock((int) $participant['id'], (int) $meeting['id']);
            $fn($locked);
        });
    }

    private function lock(int $participantId, int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, name, email, status, is_voting_eligible
             FROM participants
             WHERE id = ? AND meeting_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$participantId, $meetingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        return $row;
    }

    private function insertParticipant(int $meetingId, string $name, string $email): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO participants (public_id, meeting_id, name, email, status, is_voting_eligible)
                     VALUES (?, ?, ?, ?, ?, 0)'
                )->execute([Tokens::publicId(), $meetingId, $name, $email, 'pending']);
                return (int) $this->pdo->lastInsertId();
            } catch (PDOException $e) {
                if (!is_duplicate_key($e)) {
                    throw $e;
                }
                $message = $e->getMessage();
                if (str_contains($message, 'uq_participants_public_id') && $attempt < 4) {
                    continue;
                }
                throw new AppException(
                    'DUPLICATE_EMAIL',
                    'E-postadressen är redan anmäld till mötet. Kontakta mötesadministratören om du behöver anmäla dig på nytt.'
                );
            }
        }
        throw new AppException('RETRY', 'Kunde inte slutföra anmälan. Försök igen.');
    }

    private function mustFind(int $participantId, int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, name, email, status, is_voting_eligible
             FROM participants WHERE id = ? AND meeting_id = ?'
        );
        $stmt->execute([$participantId, $meetingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        return $row;
    }
}
