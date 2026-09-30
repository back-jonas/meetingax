<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use Meetingax\Support\Tokens;
use PDO;
use PDOException;

final class MeetingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $title, ?string $description, ?string $meetingDate): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new AppException('VALIDATION', 'Ange ett mötesnamn på högst 200 tecken.');
        }
        $description = $this->cleanDescription($description);
        $meetingDate = $this->cleanDate($meetingDate);

        return db_transaction($this->pdo, function () use ($userId, $title, $description, $meetingDate) {
            $meetingId = $this->insertMeeting($userId, $title, $description, $meetingDate);
            $this->pdo->prepare(
                'INSERT INTO meeting_roles (meeting_id, user_id, role) VALUES (?, ?, ?)'
            )->execute([$meetingId, $userId, 'owner']);
            (new AuditLog($this->pdo))->write($meetingId, $userId, null, 'MEETING_CREATED', ['title' => $title]);
            return $this->mustFind($meetingId);
        });
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.public_id, m.title, m.meeting_code, m.meeting_date, m.status, m.created_at,
                    (SELECT COUNT(*) FROM participants p WHERE p.meeting_id = m.id AND p.status = ?) AS approved_count,
                    (SELECT COUNT(*) FROM participants p WHERE p.meeting_id = m.id AND p.status = ?) AS pending_count
             FROM meetings m
             INNER JOIN meeting_roles r ON r.meeting_id = m.id
             WHERE r.user_id = ?
             ORDER BY m.created_at DESC, m.id DESC'
        );
        $stmt->execute(['approved', 'pending', $userId]);
        return $stmt->fetchAll();
    }

    public function findForUser(string $publicId, int $userId): ?array
    {
        if (!Tokens::isPublicId($publicId)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.public_id, m.owner_user_id, m.title, m.description, m.meeting_code,
                    m.meeting_date, m.status, m.created_at, m.updated_at
             FROM meetings m
             INNER JOIN meeting_roles r ON r.meeting_id = m.id AND r.user_id = ?
             WHERE m.public_id = ?'
        );
        $stmt->execute([$userId, $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $code = Tokens::normalizeMeetingCode($code);
        if (!Tokens::isMeetingCode($code)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, owner_user_id, title, description, meeting_code, meeting_date, status, created_at
             FROM meetings
             WHERE meeting_code = ?'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setStatus(array $meeting, string $status, int $userId): void
    {
        $allowed = [
            'draft' => ['open'],
            'open' => ['closed'],
            'closed' => ['open', 'archived'],
            'archived' => [],
        ];
        $from = (string) $meeting['status'];
        if (!in_array($status, $allowed[$from] ?? [], true)) {
            throw new AppException('INVALID_STATUS', 'Statusövergången är inte tillåten.');
        }
        db_transaction($this->pdo, function () use ($meeting, $status, $from, $userId) {
            $locked = $this->lock((int) $meeting['id']);
            if ($locked['status'] !== $from) {
                throw new AppException('INVALID_STATUS', 'Mötesstatus har ändrats. Ladda om sidan.');
            }
            if ($from === 'open' && $status === 'closed') {
                (new PollService($this->pdo))->closeAnyOpen((int) $meeting['id'], $userId);
            }
            $this->pdo->prepare('UPDATE meetings SET status = ? WHERE id = ?')->execute([$status, $meeting['id']]);
            (new AuditLog($this->pdo))->write((int) $meeting['id'], $userId, null, 'MEETING_STATUS_CHANGED', [
                'status' => $status,
            ]);
        });
    }

    public function lock(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, status, title, meeting_code FROM meetings WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$meetingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Mötet hittades inte.', 404);
        }
        return $row;
    }

    private function insertMeeting(int $userId, string $title, ?string $description, ?string $meetingDate): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO meetings (public_id, owner_user_id, title, description, meeting_code, meeting_date, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    Tokens::publicId(),
                    $userId,
                    $title,
                    $description,
                    Tokens::meetingCode(),
                    $meetingDate,
                    'draft',
                ]);
                return (int) $this->pdo->lastInsertId();
            } catch (PDOException $e) {
                if (is_duplicate_key($e) && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
        throw new AppException('RETRY', 'Kunde inte skapa en unik möteskod. Försök igen.');
    }

    private function mustFind(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, owner_user_id, title, description, meeting_code, meeting_date, status, created_at
             FROM meetings WHERE id = ?'
        );
        $stmt->execute([$meetingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Mötet hittades inte.', 404);
        }
        return $row;
    }

    private function cleanDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > 5000) {
            throw new AppException('VALIDATION', 'Beskrivningen är för lång.');
        }
        return $description;
    }

    private function cleanDate(?string $meetingDate): ?string
    {
        if ($meetingDate === null) {
            return null;
        }
        $meetingDate = trim($meetingDate);
        if ($meetingDate === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $meetingDate);
        if ($dt === false || $dt->format('Y-m-d') !== $meetingDate) {
            throw new AppException('VALIDATION', 'Datumet har fel format.');
        }
        return $meetingDate;
    }
}
