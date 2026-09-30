<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nl2e(?string $value): string
{
    return nl2br(e($value), false);
}

function url(string $path): string
{
    if ($path === '') {
        return '/';
    }
    return str_starts_with($path, '/') ? $path : '/' . $path;
}

function app_base_url(array $config): string
{
    $configured = rtrim((string) ($config['app']['url'] ?? ''), '/');
    if ($configured !== '' && preg_match('#^https://#', $configured) === 1) {
        return $configured;
    }
    if ($configured !== '' && preg_match('#^http://[A-Za-z0-9.-]+(?::\d+)?$#', $configured) === 1) {
        return $configured;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host) !== 1) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

function meeting_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Utkast',
        'open' => 'Öppet',
        'closed' => 'Stängt',
        'archived' => 'Arkiverat',
        default => $status,
    };
}

function participant_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Väntar på godkännande',
        'approved' => 'Godkänd',
        'rejected' => 'Avslagen',
        'removed' => 'Borttagen',
        default => $status,
    };
}

function format_percent(float $value): string
{
    $text = number_format($value, 1, ',', ' ');
    return str_ends_with($text, ',0') ? substr($text, 0, -2) : $text;
}

function voting_type_label(string $type): string
{
    return match ($type) {
        'yes_no_abstain' => 'JA / NEJ / AVSTÅR',
        'single_choice' => 'Eget val',
        'multiple_choice' => 'Flera val',
        'person' => 'Personval',
        'ranked' => 'Rangordnat val',
        default => $type,
    };
}

function poll_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Utkast',
        'open' => 'Öppen',
        'closed' => 'Stängd',
        default => $status,
    };
}

function pdo_mysql_errno(PDOException $exception): int
{
    return (int) ($exception->errorInfo[1] ?? 0);
}

function is_duplicate_key(PDOException $exception): bool
{
    return pdo_mysql_errno($exception) === 1062;
}

/**
 * Kör $fn i en transaktion. Om en transaktion redan är igång deltar anropet i den.
 */
function db_transaction(PDO $pdo, callable $fn): mixed
{
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
