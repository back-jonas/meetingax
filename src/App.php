<?php

declare(strict_types=1);

namespace Meetingax;

use Meetingax\Auth\AdminAuth;
use Meetingax\Auth\ParticipantAuth;
use Meetingax\Auth\RateLimiter;
use Meetingax\Http\Request;
use Meetingax\Support\View;
use PDO;

/**
 * Gemensamma objekt för ett webb-anrop.
 */
final class App
{
    public function __construct(
        public readonly array $config,
        public readonly PDO $pdo,
        public readonly Request $request,
        public readonly View $view,
        public readonly AdminAuth $admin,
        public readonly ParticipantAuth $participant,
        public readonly RateLimiter $rates,
    ) {
    }
}
