<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use PDO;
use PDOException;

/**
 * En röst skrivs i en transaktion. Mötes-id hämtas från de låsta raderna,
 * aldrig från webbläsaren. Databasens unika nyckel stoppar en andra röst.
 */
final class BallotService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function cast(int $participantId, string $optionKey, string $expectedPollPublicId = ''): void
    {
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $optionKey)) {
            throw new AppException('VALIDATION', 'Ogiltigt svarsalternativ.');
        }
        db_transaction($this->pdo, function () use ($participantId, $optionKey, $expectedPollPublicId) {
            $participant = $this->lockParticipant($participantId);
            $meeting = (new MeetingService($this->pdo))->lock((int) $participant['meeting_id']);
            if ($participant['status'] !== 'approved') {
                throw new AppException('NOT_APPROVED', 'Du är inte godkänd att delta i mötet.', 403);
            }
            if ((int) $participant['is_voting_eligible'] !== 1) {
                throw new AppException('NOT_ELIGIBLE', 'Du har inte rösträtt i mötet.', 403);
            }
            if ($meeting['status'] !== 'open') {
                throw new AppException('MEETING_NOT_OPEN', 'Mötet är inte öppet för omröstning.');
            }
            $poll = $this->lockOpenPoll((int) $meeting['id']);
            if ($poll === null || $poll['status'] !== 'open') {
                throw new AppException('NO_OPEN_POLL', 'Det finns ingen öppen omröstning.');
            }
            if (!in_array($poll['voting_type'], ['yes_no_abstain', 'single_choice'], true) || $poll['visibility'] !== 'open') {
                throw new AppException('UNSUPPORTED', 'Den här omröstningen kan inte ta emot röster i den här versionen.');
            }
            if ($expectedPollPublicId !== '' && !hash_equals((string) $poll['public_id'], $expectedPollPublicId)) {
                throw new AppException('POLL_CHANGED', 'Omröstningen är inte längre öppen. Ladda om sidan.');
            }
            $option = $this->pdo->prepare(
                'SELECT id FROM poll_options WHERE poll_id = ? AND option_key = ?'
            );
            $option->execute([(int) $poll['id'], $optionKey]);
            $optionId = $option->fetchColumn();
            if ($optionId === false) {
                throw new AppException('VALIDATION', 'Ogiltigt svarsalternativ.');
            }
            try {
                $this->pdo->prepare(
                    'INSERT INTO open_ballots (meeting_id, poll_id, participant_id, poll_option_id)
                     VALUES (?, ?, ?, ?)'
                )->execute([
                    (int) $participant['meeting_id'],
                    (int) $poll['id'],
                    (int) $participant['id'],
                    (int) $optionId,
                ]);
            } catch (PDOException $e) {
                if (is_duplicate_key($e)) {
                    throw new AppException('ALREADY_VOTED', 'Du har redan röstat i den här omröstningen.', 409);
                }
                throw $e;
            }
            (new AuditLog($this->pdo))->write(
                (int) $participant['meeting_id'],
                null,
                (int) $participant['id'],
                'BALLOT_SUBMITTED',
                [
                    'poll_public_id' => $poll['public_id'],
                    'visibility' => 'open',
                ]
            );
        });
    }

    private function lockParticipant(int $participantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, meeting_id, status, is_voting_eligible
             FROM participants
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$participantId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        return $row;
    }

    private function lockOpenPoll(int $meetingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.public_id, p.meeting_id, p.title, p.voting_type, p.visibility, p.status
             FROM meeting_open_poll mop
             INNER JOIN polls p ON p.id = mop.poll_id AND p.meeting_id = mop.meeting_id
             WHERE mop.meeting_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$meetingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
