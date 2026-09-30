<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Auth\RateLimiter;
use Meetingax\Domain\AppException;
use Meetingax\Domain\BallotService;
use Meetingax\Domain\FieldService;
use Meetingax\Domain\MeetingService;
use Meetingax\Domain\MeetingStateService;
use Meetingax\Domain\ParticipantService;
use Meetingax\Domain\ReturnLinkService;
use Meetingax\Http\Controller;
use Meetingax\Mail\MailerFactory;
use Meetingax\Mail\ReturnMail;
use Meetingax\Support\Flash;
use Throwable;

/** Deltagarens mobilvy: anmälan, väntan och röstning. */
final class ParticipantPageController extends Controller
{
    public function entry(string $code): void
    {
        $meeting = $this->meeting($code);
        $session = $this->sessionFor($meeting);
        if ($session === null) {
            $this->redirect($this->path($meeting, '/register'));
        }
        if ($session['status'] === 'approved') {
            $this->redirect($this->path($meeting, '/vote'));
        }
        $this->redirect($this->path($meeting, '/waiting'));
    }

    public function registerForm(string $code, array $errors = []): void
    {
        $meeting = $this->meeting($code);
        $session = $this->sessionFor($meeting);
        if ($session !== null) {
            $this->redirect($session['status'] === 'approved' ? $this->path($meeting, '/vote') : $this->path($meeting, '/waiting'));
        }
        $this->app->view->render('participant/register.php', [
            'title' => $meeting['title'],
            'meeting' => $meeting,
            'fields' => (new FieldService($this->app->pdo))->listForMeeting((int) $meeting['id']),
            'errors' => $errors,
            'name' => $this->app->request->input('name'),
            'email' => $this->app->request->input('email'),
            'postedFields' => $this->app->request->inputArray('field'),
        ], 'layout/participant.php');
    }

    public function register(string $code): void
    {
        $this->assertCsrf();
        $meeting = $this->meeting($code);
        if (!$this->app->rates->allow('participant-register', RateLimiter::clientIp(), 40, 3600)) {
            $this->registerForm($code, ['För många försök. Vänta en stund och försök igen.']);
            return;
        }
        $extracted = (new FieldService($this->app->pdo))->extract(
            (int) $meeting['id'],
            $this->app->request->inputArray('field')
        );
        if ($extracted['errors'] !== []) {
            $this->registerForm($code, $extracted['errors']);
            return;
        }
        try {
            $result = (new ParticipantService(
                $this->app->pdo,
                (int) ($this->app->config['participant_cookie']['lifetime'] ?? 43200),
                $this->returnLifetime()
            ))->register(
                $meeting,
                $this->app->request->input('name'),
                $this->app->request->input('email'),
                $extracted['values']
            );
        } catch (AppException $e) {
            $this->registerForm($code, [$e->getMessage()]);
            return;
        }
        $this->app->participant->establish($result['token']);
        if ($this->sendReturnMail($meeting, $result)) {
            Flash::set('ok', 'Vi har skickat en länk till din e-post. Spara mejlet så att du kan komma tillbaka.');
        } else {
            Flash::set('err', 'Anmälan är sparad, men länken kunde inte mejlas. Håll den här sidan öppen eller kontakta arrangören.');
        }
        $this->redirect($this->path($meeting, '/waiting'));
    }

    public function resume(string $token): void
    {
        if (!$this->app->rates->allow('return-link', RateLimiter::clientIp(), 30, 600)) {
            throw new AppException('RATE_LIMIT', 'För många försök. Vänta en stund och försök igen.', 429);
        }
        try {
            $result = (new ReturnLinkService($this->app->pdo, $this->returnLifetime()))->redeem($token);
        } catch (AppException) {
            throw new AppException('NOT_FOUND', 'Länken är ogiltig eller har gått ut.', 404);
        }
        $this->app->participant->establish($result['session_token']);
        header('Referrer-Policy: no-referrer');
        $suffix = $result['status'] === 'approved' ? '/vote' : '/waiting';
        $this->redirect('/m/' . rawurlencode($result['meeting_code']) . $suffix);
    }

    public function waiting(string $code): void
    {
        $meeting = $this->meeting($code);
        $session = $this->sessionFor($meeting);
        if ($session === null) {
            $this->redirect($this->path($meeting, '/register'));
        }
        if ($session['status'] === 'approved') {
            $this->redirect($this->path($meeting, '/vote'));
        }
        $screen = (new MeetingStateService($this->app->pdo))->participantScreen($session);
        $this->app->view->render('participant/waiting.php', [
            'title' => $meeting['title'],
            'meeting' => $meeting,
            'participant' => $session,
            'signature' => $screen['signature'],
        ], 'layout/participant.php');
    }

    public function vote(string $code): void
    {
        $meeting = $this->meeting($code);
        $session = $this->sessionFor($meeting);
        if ($session === null) {
            $this->redirect($this->path($meeting, '/register'));
        }
        if ($session['status'] !== 'approved') {
            $this->redirect($this->path($meeting, '/waiting'));
        }
        $screen = (new MeetingStateService($this->app->pdo))->participantScreen($session);
        $this->app->view->render('participant/vote.php', [
            'title' => $meeting['title'],
            'meeting' => $meeting,
            'participant' => $session,
            'screen' => $screen,
        ], 'layout/participant.php');
    }

    public function cast(string $code): void
    {
        $this->assertCsrf();
        $meeting = $this->meeting($code);
        $session = $this->sessionFor($meeting);
        if ($session === null || $session['status'] !== 'approved') {
            $this->redirect($this->path($meeting, '/register'));
        }
        if (!$this->app->rates->allow('vote', (string) $session['id'], 30, 600)) {
            Flash::set('err', 'För många försök. Vänta en stund och försök igen.');
            $this->redirect($this->path($meeting, '/vote'));
        }
        try {
            (new BallotService($this->app->pdo))->cast(
                (int) $session['id'],
                $this->app->request->input('option'),
                $this->app->request->input('poll_public_id')
            );
            Flash::set('ok', 'Din röst har registrerats.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect($this->path($meeting, '/vote'));
    }

    private function meeting(string $code): array
    {
        if (!$this->app->rates->allow('meeting-code', RateLimiter::clientIp(), 300, 600)) {
            throw new AppException('RATE_LIMIT', 'För många försök. Vänta en stund och försök igen.', 429);
        }
        $meeting = (new MeetingService($this->app->pdo))->findByCode($code);
        if (!$meeting) {
            throw new AppException('NOT_FOUND', 'Möteskoden är ogiltig.', 404);
        }
        return $meeting;
    }

    private function sessionFor(array $meeting): ?array
    {
        $session = $this->app->participant->current();
        if ($session === null || (int) $session['meeting_id'] !== (int) $meeting['id']) {
            return null;
        }
        return $session;
    }

    private function path(array $meeting, string $suffix): string
    {
        return '/m/' . rawurlencode((string) $meeting['meeting_code']) . $suffix;
    }

    private function returnLifetime(): int
    {
        $mail = is_array($this->app->config['mail'] ?? null) ? $this->app->config['mail'] : [];
        return (int) ($mail['return_link_lifetime'] ?? 604800);
    }

    private function sendReturnMail(array $meeting, array $result): bool
    {
        try {
            $token = (string) ($result['return_token'] ?? '');
            $participant = is_array($result['participant'] ?? null) ? $result['participant'] : [];
            $url = app_base_url($this->app->config) . '/ater/' . $token;
            MailerFactory::fromConfig($this->app->config)->send(
                (string) ($participant['email'] ?? ''),
                ReturnMail::subject((string) $meeting['title']),
                ReturnMail::body(
                    (string) ($participant['name'] ?? ''),
                    (string) $meeting['title'],
                    (string) $meeting['meeting_code'],
                    $url,
                    $this->returnLifetime()
                )
            );
            return true;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return false;
        }
    }
}
