<?php

declare(strict_types=1);

namespace Meetingax\Mail;

final class MailerFactory
{
    public static function fromConfig(array $config): Mailer
    {
        $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
        if (($mail['transport'] ?? 'log') === 'smtp') {
            return new SmtpMailer($mail);
        }
        $path = (string) ($mail['log_path'] ?? '');
        if ($path === '') {
            $path = dirname(__DIR__, 2) . '/storage/mail';
        }
        return new LogMailer($path);
    }
}
