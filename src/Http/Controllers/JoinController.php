<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Auth\RateLimiter;
use Meetingax\Domain\MeetingService;
use Meetingax\Http\Controller;
use Meetingax\Support\Tokens;

/** Publik ingång där deltagaren anger möteskod. */
final class JoinController extends Controller
{
    public function form(?string $error = null): void
    {
        $this->app->view->render('join/form.php', [
            'title' => 'Anslut till möte',
            'error' => $error,
            'code' => $this->app->request->input('meeting_code'),
        ], 'layout/guest.php');
    }

    public function submit(): void
    {
        $this->assertCsrf();
        if (!$this->app->rates->allow('join', RateLimiter::clientIp(), 300, 600)) {
            $this->form('För många försök. Vänta en stund och försök igen.');
            return;
        }
        $meeting = (new MeetingService($this->app->pdo))->findByCode($this->app->request->input('meeting_code'));
        if (!$meeting) {
            $this->form('Möteskoden är ogiltig.');
            return;
        }
        $this->redirect('/m/' . rawurlencode((string) $meeting['meeting_code']));
    }
}
