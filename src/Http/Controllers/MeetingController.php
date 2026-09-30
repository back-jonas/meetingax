<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Domain\AppException;
use Meetingax\Domain\AuditLog;
use Meetingax\Domain\FieldService;
use Meetingax\Domain\MeetingService;
use Meetingax\Domain\MeetingStateService;
use Meetingax\Domain\ParticipantService;
use Meetingax\Domain\PollService;
use Meetingax\Http\Controller;
use Meetingax\Support\Flash;

/** Administration av ett möte. Behörighet kontrolleras mot meeting_roles. */
final class MeetingController extends Controller
{
    public function createForm(?string $error = null): void
    {
        $this->app->admin->requireUser();
        $this->app->view->render('meeting/form.php', [
            'title' => 'Skapa möte',
            'error' => $error,
            'titleValue' => $this->app->request->input('title'),
            'description' => $this->app->request->input('description'),
            'meetingDate' => $this->app->request->input('meeting_date'),
        ]);
    }

    public function create(): void
    {
        $this->assertCsrf();
        $user = $this->app->admin->requireUser();
        try {
            $meeting = (new MeetingService($this->app->pdo))->create(
                (int) $user['id'],
                $this->app->request->input('title'),
                $this->app->request->input('description'),
                $this->app->request->input('meeting_date')
            );
        } catch (AppException $e) {
            $this->createForm($e->getMessage());
            return;
        }
        Flash::set('ok', 'Mötet är skapat. Dela möteskoden med deltagarna när du har öppnat mötet.');
        $this->redirect('/meeting/' . $meeting['public_id']);
    }

    public function show(string $publicId): void
    {
        [, $meeting] = $this->owned($publicId);
        $data = $this->pageData($meeting);
        $data['fields'] = (new FieldService($this->app->pdo))->listForMeeting((int) $meeting['id']);
        $data['events'] = (new AuditLog($this->app->pdo))->forMeeting((int) $meeting['id']);
        if (($this->app->request->query['fragment'] ?? '') === 'audit') {
            $this->app->view->partial('meeting/partials/audit_list.php', $data);
            return;
        }
        $this->app->view->render('meeting/show.php', $data);
    }

    public function updateStatus(string $publicId): void
    {
        $this->assertCsrf();
        [$user, $meeting] = $this->owned($publicId);
        try {
            (new MeetingService($this->app->pdo))->setStatus(
                $meeting,
                $this->app->request->input('status'),
                (int) $user['id']
            );
            Flash::set('ok', 'Mötesstatus uppdaterades.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect('/meeting/' . $meeting['public_id']);
    }

    public function addField(string $publicId): void
    {
        $this->assertCsrf();
        [$user, $meeting] = $this->owned($publicId);
        try {
            (new FieldService($this->app->pdo))->add(
                $meeting,
                $this->app->request->input('label'),
                $this->app->request->input('field_type'),
                $this->app->request->input('required') === '1',
                $this->app->request->input('options'),
                (int) $user['id']
            );
            Flash::set('ok', 'Fältet lades till.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect('/meeting/' . $meeting['public_id']);
    }

    public function participants(string $publicId): void
    {
        [, $meeting] = $this->owned($publicId);
        $data = $this->pageData($meeting);
        $data['participants'] = (new ParticipantService($this->app->pdo))->listForMeeting((int) $meeting['id']);
        if (($this->app->request->query['fragment'] ?? '') === 'participants') {
            $this->app->view->partial('meeting/partials/participant_table.php', $data);
            return;
        }
        $this->app->view->render('meeting/participants.php', $data);
    }

    public function participantAction(string $publicId, string $participantPublicId): void
    {
        $this->assertCsrf();
        [$user, $meeting] = $this->owned($publicId);
        $service = new ParticipantService($this->app->pdo);
        $participant = $service->findInMeeting((int) $meeting['id'], $participantPublicId);
        if (!$participant) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        try {
            match ($this->app->request->input('action')) {
                'approve' => $service->approve($meeting, $participant, (int) $user['id']),
                'reject' => $service->reject($meeting, $participant, (int) $user['id']),
                'remove' => $service->remove($meeting, $participant, (int) $user['id']),
                'grant' => $service->grantVote($meeting, $participant, (int) $user['id']),
                'revoke' => $service->revokeVote($meeting, $participant, (int) $user['id']),
                default => throw new AppException('VALIDATION', 'Okänd åtgärd.'),
            };
            Flash::set('ok', 'Deltagaren uppdaterades.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect('/meeting/' . $meeting['public_id'] . '/participants');
    }

    public function polls(string $publicId): void
    {
        [, $meeting] = $this->owned($publicId);
        $data = $this->pageData($meeting);
        $data['polls'] = (new PollService($this->app->pdo))->listForAdmin((int) $meeting['id']);
        $data['eligible'] = $data['state']['counts']['voting_eligible'];
        if (($this->app->request->query['fragment'] ?? '') === 'polls') {
            $this->app->view->partial('meeting/partials/poll_list.php', $data);
            return;
        }
        $this->app->view->render('meeting/polls.php', $data);
    }

    public function createPoll(string $publicId): void
    {
        $this->assertCsrf();
        [$user, $meeting] = $this->owned($publicId);
        try {
            (new PollService($this->app->pdo))->create(
                $meeting,
                (int) $user['id'],
                $this->app->request->input('title'),
                $this->app->request->input('description'),
                $this->app->request->input('show_results_to_participants') === '1'
            );
            Flash::set('ok', 'Omröstningen skapades som utkast.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect('/meeting/' . $meeting['public_id'] . '/votes');
    }

    public function pollAction(string $publicId, string $pollPublicId): void
    {
        $this->assertCsrf();
        [$user, $meeting] = $this->owned($publicId);
        $service = new PollService($this->app->pdo);
        $poll = $service->findInMeeting((int) $meeting['id'], $pollPublicId);
        if (!$poll) {
            throw new AppException('NOT_FOUND', 'Omröstningen hittades inte.', 404);
        }
        try {
            match ($this->app->request->input('action')) {
                'open' => $service->open($meeting, $poll, (int) $user['id']),
                'close' => $service->close($meeting, $poll, (int) $user['id']),
                default => throw new AppException('VALIDATION', 'Okänd åtgärd.'),
            };
            Flash::set('ok', 'Omröstningen uppdaterades.');
        } catch (AppException $e) {
            Flash::set('err', $e->getMessage());
        }
        $this->redirect('/meeting/' . $meeting['public_id'] . '/votes');
    }

    /** @return array{0: array, 1: array} */
    private function owned(string $publicId): array
    {
        $user = $this->app->admin->requireUser();
        $meeting = (new MeetingService($this->app->pdo))->findForUser($publicId, (int) $user['id']);
        if (!$meeting) {
            throw new AppException('NOT_FOUND', 'Mötet hittades inte.', 404);
        }
        return [$user, $meeting];
    }

    private function pageData(array $meeting): array
    {
        $state = (new MeetingStateService($this->app->pdo))->adminState($meeting);
        return [
            'title' => $meeting['title'],
            'meeting' => $meeting,
            'state' => $state,
        ];
    }
}
