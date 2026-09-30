<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use PDO;
use PDOException;

final class FieldService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listForMeeting(int $meetingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, meeting_id, label, field_key, field_type, required, options_json, sort_order
             FROM meeting_registration_fields
             WHERE meeting_id = ?
             ORDER BY sort_order, id'
        );
        $stmt->execute([$meetingId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = $this->optionsOf($row);
        }
        unset($row);
        return $rows;
    }

    public function add(array $meeting, string $label, string $type, bool $required, string $optionsText, int $userId): void
    {
        if (!in_array($meeting['status'], ['draft', 'open'], true)) {
            throw new AppException('INVALID_STATE', 'Fält kan bara läggas till medan mötet är utkast eller öppet.');
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new AppException('VALIDATION', 'Ange en fältrubrik på högst 120 tecken.');
        }
        if (!in_array($type, ['text', 'number', 'select'], true)) {
            throw new AppException('VALIDATION', 'Fälttypen stöds inte.');
        }
        $options = null;
        if ($type === 'select') {
            $options = $this->parseOptions($optionsText);
            if (count($options) < 1) {
                throw new AppException('VALIDATION', 'En lista behöver minst ett alternativ.');
            }
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM meeting_registration_fields WHERE meeting_id = ?');
        $count->execute([(int) $meeting['id']]);
        if ((int) $count->fetchColumn() >= 20) {
            throw new AppException('LIMIT', 'Mötet kan ha högst 20 extra fält.');
        }
        $key = $this->uniqueKey((int) $meeting['id'], $this->fieldKey($label));
        $sort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM meeting_registration_fields WHERE meeting_id = ?');
        $sort->execute([(int) $meeting['id']]);
        $sortOrder = (int) $sort->fetchColumn();
        try {
            $this->pdo->prepare(
                'INSERT INTO meeting_registration_fields
                 (meeting_id, label, field_key, field_type, required, options_json, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $meeting['id'],
                $label,
                $key,
                $type,
                $required ? 1 : 0,
                $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $sortOrder,
            ]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) {
                throw new AppException('DUPLICATE', 'Fältet finns redan.');
            }
            throw $e;
        }
        (new AuditLog($this->pdo))->write((int) $meeting['id'], $userId, null, 'FIELD_ADDED', ['label' => $label]);
    }

    /**
     * @param array<string, mixed> $posted
     * @return array{errors: list<string>, values: array<int, string>}
     */
    public function extract(int $meetingId, array $posted): array
    {
        $errors = [];
        $values = [];
        foreach ($this->listForMeeting($meetingId) as $field) {
            $raw = $posted[$field['field_key']] ?? '';
            if (!is_string($raw)) {
                $raw = '';
            }
            $raw = trim($raw);
            if ($raw === '') {
                if ((int) $field['required'] === 1) {
                    $errors[] = $field['label'] . ' är obligatoriskt.';
                }
                continue;
            }
            if (mb_strlen($raw) > 500) {
                $errors[] = $field['label'] . ' är för långt.';
                continue;
            }
            if ($field['field_type'] === 'number') {
                if (preg_match('/^\d+([.,]\d+)?$/', $raw) !== 1) {
                    $errors[] = $field['label'] . ' måste vara ett tal.';
                    continue;
                }
                $raw = str_replace(',', '.', $raw);
            }
            if ($field['field_type'] === 'select' && !in_array($raw, $field['options'], true)) {
                $errors[] = $field['label'] . ' har ett ogiltigt val.';
                continue;
            }
            $values[(int) $field['id']] = $raw;
        }
        return ['errors' => $errors, 'values' => $values];
    }

    /** @param array<int, string> $values */
    public function saveValues(int $meetingId, int $participantId, array $values): void
    {
        $allowed = [];
        foreach ($this->listForMeeting($meetingId) as $field) {
            $allowed[(int) $field['id']] = true;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO participant_field_values (meeting_id, participant_id, registration_field_id, value)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($values as $fieldId => $value) {
            if (!isset($allowed[(int) $fieldId])) {
                continue;
            }
            $stmt->execute([$meetingId, $participantId, (int) $fieldId, $value]);
        }
    }

    /** @param list<int> $participantIds */
    public function valuesByParticipant(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT v.participant_id, v.value, f.label, f.sort_order
             FROM participant_field_values v
             INNER JOIN meeting_registration_fields f ON f.id = v.registration_field_id
             WHERE v.participant_id IN ($placeholders)
             ORDER BY f.sort_order, f.id"
        );
        $stmt->execute(array_values($participantIds));
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['participant_id']][] = [
                'label' => (string) $row['label'],
                'value' => (string) $row['value'],
            ];
        }
        return $grouped;
    }

    private function uniqueKey(int $meetingId, string $base): string
    {
        $key = $base;
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM meeting_registration_fields WHERE meeting_id = ? AND field_key = ?'
        );
        for ($i = 2; $i < 50; $i++) {
            $stmt->execute([$meetingId, $key]);
            if (!$stmt->fetchColumn()) {
                return $key;
            }
            $key = mb_substr($base, 0, 50) . '_' . $i;
        }
        throw new AppException('DUPLICATE', 'Kunde inte skapa ett unikt fältnamn.');
    }

    private function fieldKey(string $label): string
    {
        $s = mb_strtolower(trim($label), 'UTF-8');
        $s = strtr($s, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        $s = trim($s, '_');
        if ($s === '' || preg_match('/^[a-z]/', $s) !== 1) {
            $s = trim('falt_' . $s, '_');
        }
        return mb_substr($s, 0, 60);
    }

    /** @return list<string> */
    private function parseOptions(string $text): array
    {
        $options = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 120) {
                if ($line !== '') {
                    throw new AppException('VALIDATION', 'Varje listalternativ får vara högst 120 tecken.');
                }
                continue;
            }
            if (!in_array($line, $options, true)) {
                $options[] = $line;
            }
            if (count($options) > 30) {
                throw new AppException('VALIDATION', 'En lista kan ha högst 30 alternativ.');
            }
        }
        return $options;
    }

    /** @return list<string> */
    private function optionsOf(array $field): array
    {
        $json = $field['options_json'] ?? null;
        if (is_string($json)) {
            $decoded = json_decode($json, true);
        } elseif (is_array($json)) {
            $decoded = $json;
        } else {
            $decoded = [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $options = [];
        foreach ($decoded as $option) {
            if (is_string($option) && $option !== '') {
                $options[] = $option;
            }
        }
        return $options;
    }
}
