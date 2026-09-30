<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Domain\AppException;
use Meetingax\Domain\BallotService;
use Meetingax\Domain\MeetingService;
use Meetingax\Domain\MeetingStateService;
use Meetingax\Domain\ParticipantService;
use Meetingax\Domain\PollService;
use Meetingax\Http\Controller;
use Meetingax\Http\Json;

/** JSON-API. Samma regler som sidorna, utan att lita på klientens id:n. */
final class ApiController extends Controller
{
    public function meetingStatus(): void
    {
        $participant = $this->app->participant->requireApi();
        Json::ok([
            'meeting_status' => $participant['meeting_status'],
            'title' => $participant['meeting_title'],
            'meeting_code' => $participant['meeting_code'],
        ]);
    }

    public function participantStatus(): void
    {
        $participant = $this->app->participant->requireApi();
        Json::ok([
            'status' => $participant['status'],
            'is_voting_eligible' => (int) $participant['is_voting_eligible'] === 1,
        ]);
    }

    public function participantState(): void
    {
        $participant = $this->app->participant->requireApi();
        $screen = (new MeetingStateService($this->app->pdo))->participantScreen($participant);
        Json::ok([
            'signature' => $screen['signature'],
            'participant_status' => $participant['status'],
            'meeting_status' => $participant['meeting_status'],
            'is_voting_eligible' => (int) $participant['is_voting_eligible'] === 1,
        ]);
    }

    public function voteCurrent(): void
    {
        $participant = $this->approvedParticipant();
        $screen = (new MeetingStateService($this->app->pdo))->participantScreen($participant);
        Json::ok([
            'mode' => $screen['mode'],
            'poll' => $screen['poll'],
            'options' => array_map(static fn (array $option): array => [
                'option_key' => $option['option_key'],
                'label' => $option['label'],
            ], $screen['options']),
            'own_choice' => $screen['own_choice'],
            'results' => $screen['results'],
            'turnout' => $screen['turnout'],
        ]);
    }

    public function voteSubmit(): void
    {
        $this->assertCsrf();
        $participant = $this->approvedParticipant();
        if (!$this->app->rates->allow('vote', (string) $participant['id'], 30, 600)) {
            throw new AppException('RATE_LIMIT', 'För många försök. Vänta en stund och försök igen.', 429);
        }
        (new BallotService($this->app->pdo))->cast(
            (int) $participant['id'],
            $this->app->request->input('option'),
            $this->app->request->input('poll_public_id')
        );
        Json::ok(['registered' => true]);
    }

    public function adminState(): void
    {
        $meeting = $this->ownedMeeting($this->app->request->input('meeting'));
        Json::ok((new MeetingStateService($this->app->pdo))->adminState($meeting));
    }

    public function participantCommand(string $action): void
    {
        $this->assertCsrf();
        $user = $this->app->admin->requireApi();
        $meeting = $this->ownedMeeting($this->app->request->input('meeting_public_id'));
        $service = new ParticipantService($this->app->pdo);
        $participant = $service->findInMeeting((int) $meeting['id'], $this->app->request->input('participant_public_id'));
        if (!$participant) {
            throw new AppException('NOT_FOUND', 'Deltagaren hittades inte.', 404);
        }
        match ($action) {
            'approve' => $service->approve($meeting, $participant, (int) $user['id']),
            'reject' => $service->reject($meeting, $participant, (int) $user['id']),
            'remove' => $service->remove($meeting, $participant, (int) $user['id']),
            'grant' => $service->grantVote($meeting, $participant, (int) $user['id']),
            'revoke' => $service->revokeVote($meeting, $participant, (int) $user['id']),
            default => throw new AppException('VALIDATION', 'Okänd åtgärd.'),
        };
        $updated = $service->findInMeeting((int) $meeting['id'], (string) $participant['public_id']);
        Json::ok([
            'public_id' => $participant['public_id'],
            'status' => $updated['status'] ?? null,
            'is_voting_eligible' => $updated ? (int) $updated['is_voting_eligible'] === 1 : false,
        ]);
    }

    public function pollCommand(string $action): void
    {
        $this->assertCsrf();
        $user = $this->app->admin->requireApi();
        $meeting = $this->ownedMeeting($this->app->request->input('meeting_public_id'));
        $service = new PollService($this->app->pdo);
        $poll = $service->findInMeeting((int) $meeting['id'], $this->app->request->input('poll_public_id'));
        if (!$poll) {
            throw new AppException('NOT_FOUND', 'Omröstningen hittades inte.', 404);
        }
        match ($action) {
            'open' => $service->open($meeting, $poll, (int) $user['id']),
            'close' => $service->close($meeting, $poll, (int) $user['id']),
            default => throw new AppException('VALIDATION', 'Okänd åtgärd.'),
        };
        $updated = $service->findInMeeting((int) $meeting['id'], (string) $poll['public_id']);
        Json::ok([
            'public_id' => $poll['public_id'],
            'status' => $updated['status'] ?? null,
            'submitted_count' => $updated ? $service->submittedCount((int) $updated['id']) : 0,
        ]);
    }

    private function approvedParticipant(): array
    {
        $participant = $this->app->participant->requireApi();
        if ($participant['status'] !== 'approved') {
            throw new AppException('PARTICIPANT_PENDING', 'Du väntar på godkännande.', 403);
        }
        return $participant;
    }

    private function ownedMeeting(string $publicId): array
    {
        $user = $this->app->admin->requireApi();
        $meeting = (new MeetingService($this->app->pdo))->findForUser($publicId, (int) $user['id']);
        if (!$meeting) {
            throw new AppException('NOT_FOUND', 'Mötet hittades inte.', 404);
        }
        return $meeting;
    }
}
