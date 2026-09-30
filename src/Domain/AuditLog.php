<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use PDO;

/**
 * Händelselogg. Ett avgivet val skrivs aldrig hit, så loggen inte kan
 * koppla en deltagare till ett svarsalternativ.
 */
final class AuditLog
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function write(
        ?int $meetingId,
        ?int $userId,
        ?int $participantId,
        string $eventType,
        ?array $data = null,
    ): void {
        if ($eventType === 'BALLOT_SUBMITTED' && is_array($data)) {
            unset($data['option'], $data['option_key'], $data['selected_option'], $data['value'], $data['label']);
        }
        $json = $data === null
            ? null
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (meeting_id, user_id, participant_id, event_type, event_data_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$meetingId, $userId, $participantId, $eventType, $json]);
    }

    public function forMeeting(int $meetingId, int $limit = 40): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, meeting_id, user_id, participant_id, event_type, event_data_json, created_at
             FROM audit_log
             WHERE meeting_id = ?
             ORDER BY id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$meetingId]);
        return $stmt->fetchAll();
    }

    public function present(array $event): string
    {
        return self::label($event);
    }

    public static function label(array $event): string
    {
        $data = self::decodeValue($event['event_data_json'] ?? null);
        $name = (string) ($data['name'] ?? 'Deltagare');
        $title = (string) ($data['title'] ?? 'Omröstning');
        return match ((string) $event['event_type']) {
            'MEETING_CREATED' => 'Möte skapat',
            'MEETING_STATUS_CHANGED' => match ($data['status'] ?? '') {
                'open' => 'Mötet öppnades',
                'closed' => 'Mötet stängdes',
                'archived' => 'Mötet arkiverades',
                default => 'Mötesstatus ändrades',
            },
            'PARTICIPANT_REGISTERED' => $name . ' anmälde sig',
            'RETURN_LINK_USED' => $name . ' kom tillbaka via mejllänken',
            'PARTICIPANT_APPROVED' => $name . ' godkändes',
            'PARTICIPANT_REJECTED' => $name . ' avslogs',
            'PARTICIPANT_REMOVED' => $name . ' togs bort',
            'VOTING_RIGHT_GRANTED' => 'Rösträtt gavs till ' . $name,
            'VOTING_RIGHT_REVOKED' => 'Rösträtt togs bort för ' . $name,
            'FIELD_ADDED' => 'Registreringsfält lades till: ' . (string) ($data['label'] ?? ''),
            'VOTE_CREATED' => 'Omröstning skapad: ' . $title,
            'VOTE_OPENED' => 'Omröstning öppnad: ' . $title,
            'BALLOT_SUBMITTED' => 'En röst registrerades',
            'VOTE_CLOSED' => 'Omröstning stängd: ' . $title . ' (' . (int) ($data['submitted_count'] ?? 0) . ' röster)',
            default => (string) $event['event_type'],
        };
    }

    private static function decodeValue(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
