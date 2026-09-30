<?php

declare(strict_types=1);

namespace Meetingax\Mail;

use Throwable;

/**
 * Skickar återlänken. Ett mejlfel ska inte visa serverdetaljer för användaren.
 */
final class ReturnNotifier
{
    public static function send(array $config, array $meeting, string $name, string $email, string $token): bool
    {
        try {
            $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
            $lifetime = (int) ($mail['return_link_lifetime'] ?? 604800);
            MailerFactory::fromConfig($config)->send(
                $email,
                ReturnMail::subject((string) $meeting['title']),
                ReturnMail::body(
                    $name,
                    (string) $meeting['title'],
                    (string) $meeting['meeting_code'],
                    app_base_url($config) . '/ater/' . $token,
                    $lifetime
                )
            );
            return true;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return false;
        }
    }
}
