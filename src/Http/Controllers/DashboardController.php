<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Domain\MeetingService;
use Meetingax\Http\Controller;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->app->admin->requireUser();
        $meetings = (new MeetingService($this->app->pdo))->listForUser((int) $user['id']);
        $this->app->view->render('dashboard/index.php', [
            'title' => 'Mina möten',
            'meetings' => $meetings,
        ]);
    }
}
