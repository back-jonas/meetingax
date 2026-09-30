<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use PDO;

/**
 * Bygger det läge som gränssnitt och polling får se.
 * Resultatfördelning lämnas bara ut när omröstningen är stängd,
 * och till deltagare bara om mötet tillåter det.
 */
final class MeetingStateService
{
    private PollService $polls;

    public function __construct(private readonly PDO $pdo)
    {
        $this->polls = new PollService($pdo);
    }

    public function adminState(array $meeting): array
    {
        $meetingId = (int) $meeting['id'];
        $participants = new ParticipantService($this->pdo);
        $counts = $participants->counts($meetingId);
        $people = $participants->listForMeeting($meetingId);
        $polls = $this->polls->listForAdmin($meetingId);
        $presence = [];
        foreach ($people as $person) {
            $presence[] = [
                'public_id' => $person['public_id'],
                'label' => $person['presence_label'],
            ];
        }
        $summaries = [];
        $open = null;
        foreach ($polls as $poll) {
            $summaries[] = [
                'public_id' => $poll['public_id'],
                'status' => $poll['status'],
                'submitted_count' => $poll['submitted_count'],
            ];
            if ($poll['status'] === 'open') {
                $open = [
                    'public_id' => $poll['public_id'],
                    'title' => $poll['title'],
                    'submitted_count' => $poll['submitted_count'],
                    'eligible_count' => $counts['voting_eligible'],
                ];
            }
        }
        return [
            'meeting_status' => $meeting['status'],
            'meeting_status_label' => meeting_status_label((string) $meeting['status']),
            'counts' => $counts,
            'presence' => $presence,
            'polls' => $summaries,
            'open_poll' => $open,
            'signature' => $this->adminSignature($meeting, $people, $polls),
        ];
    }

    public function adminSignature(array $meeting, array $people, array $polls): string
    {
        $parts = [(string) $meeting['status']];
        foreach ($people as $person) {
            $parts[] = $person['public_id'] . ':' . $person['status'] . ':' . (int) $person['is_voting_eligible'] . ':' . $person['email'];
        }
        foreach ($polls as $poll) {
            $parts[] = $poll['public_id'] . ':' . $poll['status'] . ':' . (int) $poll['show_results_to_participants'];
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    public function participantScreen(array $participant): array
    {
        $meetingId = (int) $participant['meeting_id'];
        $open = $this->polls->currentOpen($meetingId);
        $screen = [
            'signature' => $this->participantSignature($participant, $open),
            'meeting_status' => (string) $participant['meeting_status'],
            'is_voting_eligible' => (int) $participant['is_voting_eligible'] === 1,
            'mode' => 'pending',
            'poll' => null,
            'options' => [],
            'own_choice' => null,
            'results' => null,
            'turnout' => null,
        ];
        if ($participant['status'] !== 'approved') {
            return $screen;
        }
        if ($open !== null) {
            $screen['poll'] = $this->publicPoll($open);
            $screen['options'] = $this->polls->options((int) $open['id']);
            if ((int) $participant['is_voting_eligible'] !== 1) {
                $screen['mode'] = 'not_eligible';
                return $screen;
            }
            $choice = $this->polls->ownChoice((int) $open['id'], (int) $participant['id']);
            if ($choice !== null) {
                $screen['mode'] = 'voted';
                $screen['own_choice'] = $choice;
                return $screen;
            }
            $screen['mode'] = 'vote';
            return $screen;
        }
        $closed = $this->polls->latestClosed($meetingId);
        if ($closed === null) {
            $screen['mode'] = 'waiting';
            return $screen;
        }
        $screen['poll'] = $this->publicPoll($closed);
        if ((int) $closed['show_results_to_participants'] !== 1) {
            $screen['mode'] = 'results_hidden';
            return $screen;
        }
        $screen['mode'] = 'results';
        $screen['results'] = $this->polls->results((int) $closed['id']);
        $screen['turnout'] = $this->polls->turnout((int) $closed['id'], $meetingId);
        return $screen;
    }

    private function participantSignature(array $participant, ?array $open): string
    {
        $closed = $this->polls->latestClosed((int) $participant['meeting_id']);
        $voted = 0;
        if ($open !== null) {
            $voted = $this->polls->hasVoted((int) $open['id'], (int) $participant['id']) ? 1 : 0;
        }
        $payload = [
            'ps' => (string) $participant['status'],
            've' => (int) $participant['is_voting_eligible'],
            'ms' => (string) $participant['meeting_status'],
            'op' => $open['public_id'] ?? '',
            'voted' => $voted,
            'cp' => $closed['public_id'] ?? '',
            'sr' => $closed ? (int) $closed['show_results_to_participants'] : 0,
        ];
        return substr(hash('sha256', (string) json_encode($payload)), 0, 16);
    }

    private function publicPoll(array $poll): array
    {
        return [
            'public_id' => $poll['public_id'],
            'title' => $poll['title'],
            'description' => $poll['description'],
            'status' => $poll['status'],
        ];
    }
}
