<?php

declare(strict_types=1);

namespace Meetingax\Support;

final class Validator
{
    public static function name(string $name, int $max, string $label): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return $label . ' måste anges.';
        }
        if (mb_strlen($name) > $max) {
            return $label . ' får vara högst ' . $max . ' tecken.';
        }
        return null;
    }

    public static function email(string $email): ?string
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Ange en giltig e-postadress.';
        }
        return null;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function password(string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < 10) {
            return 'Lösenordet måste vara minst 10 tecken.';
        }
        if ($length > 200) {
            return 'Lösenordet är för långt.';
        }
        return null;
    }
}
