<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use Meetingax\Support\Tokens;
use PDO;
use PDOException;

final class PollService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $meeting, int $userId, string $title, ?string $description, bool $showResults): array
    {
        if (!in_array($meeting['status'], ['draft', 'open'], true)) {
            throw new AppException('INVALID_STATE', 'Omröstningar kan bara skapas medan mötet är utkast eller öppet.');
        }
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new AppException('VALIDATION', 'Ange en rubrik på högst 200 tecken.');
        }
        if ($description !== null) {
            $description = trim($description);
            if ($description === '') {
                $description = null;
            } elseif (mb_strlen($description) > 5000) {
                throw new AppException('VALIDATION', 'Beskrivningen är för lång.');
            }
        }
        return db_transaction($this->pdo, function () use ($meeting, $userId, $title, $description, $showResults) {
            $pollId = $this->insertPoll((int) $meeting['id'], $userId, $title, $description, $showResults);
            $stmt = $this->pdo->prepare(
                'INSERT INTO poll_options (poll_id, option_key, label, sort_order) VALUES (?, ?, ?, ?)'
            );
            foreach ([['yes', 'JA', 1], ['no', 'NEJ', 2], ['abstain', 'AVSTÅR', 3]] as $option) {
                $stmt->execute([$pollId, $option[0], $option[1], $option[2]]);
            }
            (new AuditLog($this->pdo))->write((int) $meeting['id'], $userId, null, 'VOTE_CREATED', [
                'title' => $title,
            ]);
            return $this->mustFind($pollId, (int) $meeting['id']);
        });
    }

    public function listForAdmin(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, title, description, voting_type, visibility,
                    show_results_to_participants, status, opened_at, closed_at, created_at
             FROM polls
             WHERE meeting_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$meetingId]);
        $polls = $stmt->fetchAll();
        foreach ($polls as &$poll) {
            $poll['submitted_count'] = $this->submittedCount((int) $poll['id']);
            if ($poll['status'] === 'closed') {
                $poll['results'] = $this->results((int) $poll['id']);
                $poll['turnout'] = $this->turnout((int) $poll['id'], $meetingId);
            }
        }
        unset($poll);
        return $polls;
    }

    public function findInMeeting(int $meetingId, string $publicId): ?array
    {
        if (!Tokens::isPublicId($publicId)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, title, description, voting_type, visibility,
                    show_results_to_participants, status, opened_at, closed_at
             FROM polls
             WHERE meeting_id = ? AND public_id = ?'
        );
        $stmt->execute([$meetingId, $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function currentOpen(int $meetingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.public_id, p.meeting_id, p.title, p.description, p.voting_type, p.visibility,
                    p.show_results_to_participants, p.status
             FROM meeting_open_poll mop
             INNER JOIN polls p ON p.id = mop.poll_id AND p.meeting_id = mop.meeting_id
             WHERE mop.meeting_id = ?'
        );
        $stmt->execute([$meetingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function latestClosed(int $meetingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, title, description, voting_type, visibility,
                    show_results_to_participants, status, closed_at
             FROM polls
             WHERE meeting_id = ? AND status = ?
             ORDER BY closed_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$meetingId, 'closed']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function options(int $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, option_key, label, sort_order
             FROM poll_options
             WHERE poll_id = ?
             ORDER BY sort_order, id'
        );
        $stmt->execute([$pollId]);
        return $stmt->fetchAll();
    }

    public function open(array $meeting, array $poll, int $userId): void
    {
        db_transaction($this->pdo, function () use ($meeting, $poll, $userId) {
            $lockedMeeting = (new MeetingService($this->pdo))->lock((int) $meeting['id']);
            $locked = $this->lock((int) $poll['id'], (int) $meeting['id']);
            if ($lockedMeeting['status'] !== 'open') {
                throw new AppException('MEETING_NOT_OPEN', 'Mötet måste vara öppet innan en omröstning kan öppnas.');
            }
            if ($locked['status'] !== 'draft') {
                throw new AppException('INVALID_STATE', 'Bara ett utkast kan öppnas.');
            }
            $this->assertSupported($locked);
            try {
                $this->pdo->prepare(
                    'INSERT INTO meeting_open_poll (meeting_id, poll_id) VALUES (?, ?)'
                )->execute([(int) $lockedMeeting['id'], (int) $locked['id']]);
            } catch (PDOException $e) {
                if (is_duplicate_key($e)) {
                    throw new AppException('POLL_ALREADY_OPEN', 'Det finns redan en öppen omröstning i mötet.');
                }
                throw $e;
            }
            $this->pdo->prepare(
                "UPDATE polls SET status = 'open', opened_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'draft'"
            )->execute([(int) $locked['id']]);
            (new AuditLog($this->pdo))->write((int) $meeting['id'], $userId, null, 'VOTE_OPENED', [
                'title' => $locked['title'],
            ]);
        });
    }

    public function close(array $meeting, array $poll, int $userId): void
    {
        db_transaction($this->pdo, function () use ($meeting, $poll, $userId) {
            (new MeetingService($this->pdo))->lock((int) $meeting['id']);
            $this->closeLocked((int) $meeting['id'], (int) $poll['id'], $userId);
        });
    }

    public function closeAnyOpen(int $meetingId, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT poll_id FROM meeting_open_poll WHERE meeting_id = ? FOR UPDATE');
        $stmt->execute([$meetingId]);
        $row = $stmt->fetch();
        if ($row) {
            $this->closeLocked($meetingId, (int) $row['poll_id'], $userId);
        }
    }

    public function submittedCount(int $pollId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM open_ballots WHERE poll_id = ?');
        $stmt->execute([$pollId]);
        return (int) $stmt->fetchColumn();
    }

    public function results(int $pollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT po.option_key, po.label,
                    (SELECT COUNT(*) FROM open_ballots ob WHERE ob.poll_option_id = po.id) AS votes
             FROM poll_options po
             WHERE po.poll_id = ?
             ORDER BY po.sort_order, po.id'
        );
        $stmt->execute([$pollId]);
        $rows = $stmt->fetchAll();
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['votes'];
        }
        foreach ($rows as &$row) {
            $votes = (int) $row['votes'];
            $row['votes'] = $votes;
            $row['percent'] = $total > 0 ? round($votes * 1000 / $total) / 10 : 0.0;
        }
        unset($row);
        return $rows;
    }

    public function turnout(int $pollId, int $meetingId): array
    {
        $eligibleStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM participants WHERE meeting_id = ? AND status = ? AND is_voting_eligible = 1'
        );
        $eligibleStmt->execute([$meetingId, 'approved']);
        $eligible = (int) $eligibleStmt->fetchColumn();
        $submitted = $this->submittedCount($pollId);
        $notVoted = max(0, $eligible - $submitted);
        return [
            'eligible' => $eligible,
            'submitted' => $submitted,
            'not_voted' => $notVoted,
            'participation_percent' => $eligible > 0 ? round($submitted * 1000 / $eligible) / 10 : 0.0,
        ];
    }

    public function ownChoice(int $pollId, int $participantId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT po.label
             FROM open_ballots ob
             INNER JOIN poll_options po ON po.id = ob.poll_option_id
             WHERE ob.poll_id = ? AND ob.participant_id = ?'
        );
        $stmt->execute([$pollId, $participantId]);
        $label = $stmt->fetchColumn();
        return $label === false ? null : (string) $label;
    }

    public function hasVoted(int $pollId, int $participantId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM open_ballots WHERE poll_id = ? AND participant_id = ?'
        );
        $stmt->execute([$pollId, $participantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function closeLocked(int $meetingId, int $pollId, int $userId): void
    {
        $locked = $this->lock($pollId, $meetingId);
        if ($locked['status'] !== 'open') {
            throw new AppException('INVALID_STATE', 'Omröstningen är inte öppen.');
        }
        $this->pdo->prepare(
            "UPDATE polls SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'"
        )->execute([$pollId]);
        $this->pdo->prepare(
            'DELETE FROM meeting_open_poll WHERE meeting_id = ? AND poll_id = ?'
        )->execute([$meetingId, $pollId]);
        (new AuditLog($this->pdo))->write($meetingId, $userId, null, 'VOTE_CLOSED', [
            'title' => $locked['title'],
            'submitted_count' => $this->submittedCount($pollId),
        ]);
    }

    private function lock(int $pollId, int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, title, voting_type, visibility, status
             FROM polls
             WHERE id = ? AND meeting_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$pollId, $meetingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Omröstningen hittades inte.', 404);
        }
        return $row;
    }

    private function assertSupported(array $poll): void
    {
        if (($poll['voting_type'] ?? '') !== 'yes_no_abstain' || ($poll['visibility'] ?? '') !== 'open') {
            throw new AppException('UNSUPPORTED', 'Den här omröstningstypen kan inte öppnas i den här versionen.');
        }
    }

    private function insertPoll(int $meetingId, int $userId, string $title, ?string $description, bool $showResults): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO polls
                     (public_id, meeting_id, title, description, voting_type, visibility, show_results_to_participants, status, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    Tokens::publicId(),
                    $meetingId,
                    $title,
                    $description,
                    'yes_no_abstain',
                    'open',
                    $showResults ? 1 : 0,
                    'draft',
                    $userId,
                ]);
                return (int) $this->pdo->lastInsertId();
            } catch (PDOException $e) {
                if (is_duplicate_key($e) && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
        throw new AppException('RETRY', 'Kunde inte skapa omröstningen. Försök igen.');
    }

    private function mustFind(int $pollId, int $meetingId): array
    {
        $row = $this->pdo->prepare(
            'SELECT id, public_id, meeting_id, title, status, show_results_to_participants
             FROM polls WHERE id = ? AND meeting_id = ?'
        );
        $row->execute([$pollId, $meetingId]);
        $poll = $row->fetch();
        if (!$poll) {
            throw new AppException('NOT_FOUND', 'Omröstningen hittades inte.', 404);
        }
        return $poll;
    }
}
