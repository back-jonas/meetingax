<?php

declare(strict_types=1);

namespace Meetingax\Mail;

use Meetingax\Domain\AppException;

/**
 * Skriver mejlet till en fil. Används i utveckling och i testerna.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new AppException('MAIL_FAILED', 'Mejlet kunde inte skickas.');
        }
        $file = $this->directory . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.txt';
        $payload = 'To: ' . $to . "\nSubject: " . $subject . "\n\n" . $text . "\n";
        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new AppException('MAIL_FAILED', 'Mejlet kunde inte skickas.');
        }
    }
}
