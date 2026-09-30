<?php

declare(strict_types=1);

namespace Meetingax\Http;

use Meetingax\App;
use Meetingax\Domain\AppException;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function assertCsrf(): void
    {
        $token = $this->app->request->input('_csrf');
        if ($token === '') {
            $token = (string) $this->app->request->header('X-CSRF-Token');
        }
        if (!Csrf::validate($token)) {
            throw new AppException(
                'CSRF',
                'Ogiltig eller saknad säkerhetstoken. Ladda om sidan och försök igen.',
                419
            );
        }
    }

    protected function redirect(string $to): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $to, true, 302);
        header('Cache-Control: no-store, private');
        exit;
    }

    protected function safeNext(string $next): string
    {
        if (
            $next === ''
            || !str_starts_with($next, '/')
            || str_starts_with($next, '//')
            || str_contains($next, '\\')
            || str_contains($next, "\n")
            || str_contains($next, "\r")
            || str_contains($next, '://')
        ) {
            return '/dashboard';
        }
        return $next;
    }
}
