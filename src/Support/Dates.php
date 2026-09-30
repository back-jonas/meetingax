<?php

declare(strict_types=1);

namespace Meetingax\Support;

/**
 * Lagring sker i UTC. Visning sker i svensk tid.
 */
final class Dates
{
    private const DISPLAY_TZ = 'Europe/Stockholm';

    public static function formatTime(?string $utc): string
    {
        $dt = self::parse($utc);
        return $dt ? $dt->format('H:i') : '–';
    }

    public static function formatDateTime(?string $utc): string
    {
        $dt = self::parse($utc);
        return $dt ? $dt->format('Y-m-d H:i') : '–';
    }

    public static function isOnline(?string $utc, int $seconds = 20): bool
    {
        $dt = self::parse($utc);
        if ($dt === null) {
            return false;
        }
        return $dt->getTimestamp() >= time() - $seconds;
    }

    public static function presence(?string $utc): string
    {
        if (self::isOnline($utc)) {
            return 'Aktiv';
        }
        if ($utc === null || $utc === '') {
            return 'Inte sedd';
        }
        return 'Senast ' . self::formatTime($utc);
    }

    private static function parse(?string $utc): ?\DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone(self::DISPLAY_TZ));
    }
}
