<?php

declare(strict_types=1);

namespace Meetingax\Support;

/**
 * Slumpade identifierare som inte avslöjar databasens löpnummer.
 */
final class Tokens
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function publicId(int $length = 12): string
    {
        return self::fromAlphabet($length);
    }

    public static function meetingCode(): string
    {
        return self::fromAlphabet(3) . '-' . self::fromAlphabet(4);
    }

    public static function sessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeMeetingCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^[A-Z0-9]{7}$/', $code) === 1) {
            $code = substr($code, 0, 3) . '-' . substr($code, 3);
        }
        return $code;
    }

    public static function isMeetingCode(string $code): bool
    {
        return preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{3}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/', $code) === 1;
    }

    public static function isPublicId(string $value): bool
    {
        return preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}$/', $value) === 1;
    }

    private static function fromAlphabet(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
