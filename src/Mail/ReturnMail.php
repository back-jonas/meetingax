<?php

declare(strict_types=1);

namespace Meetingax\Mail;

/**
 * Texten i mejlet som skickas vid anmälan. Innehåller aldrig ett röstval.
 */
final class ReturnMail
{
    public static function subject(string $meetingTitle): string
    {
        return 'Din länk till ' . self::singleLine($meetingTitle);
    }

    public static function body(
        string $name,
        string $meetingTitle,
        string $meetingCode,
        string $url,
        int $lifetimeSeconds,
    ): string {
        return implode("\n", [
            'Hej ' . self::singleLine($name) . ',',
            '',
            'Du är anmäld till ' . self::singleLine($meetingTitle) . '.',
            'Spara det här mejlet. Om du stänger webbläsaren öppnar du samma anmälan med länken:',
            '',
            self::singleLine($url),
            '',
            'Möteskod: ' . self::singleLine($meetingCode),
            '',
            self::lifetimeSentence($lifetimeSeconds),
            'Länken är personlig. Dela den inte.',
            '',
            'Meetingax',
        ]);
    }

    public static function lifetimeSentence(int $seconds): string
    {
        if ($seconds >= 86400) {
            $days = intdiv($seconds, 86400);
            return 'Länken fungerar i ' . $days . ' ' . ($days === 1 ? 'dag' : 'dagar') . '.';
        }
        $hours = max(1, intdiv($seconds, 3600));
        return 'Länken fungerar i ' . $hours . ' ' . ($hours === 1 ? 'timme' : 'timmar') . '.';
    }

    private static function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
