<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Http\Controller;

/** Startsida med vägar för arrangör och deltagare. */
final class HomeController extends Controller
{
    public function index(): void
    {
        if ($this->app->admin->user()) {
            $this->redirect('/dashboard');
        }
        $this->app->view->render('home.php', [
            'title' => 'Meetingax',
        ], 'layout/guest.php');
    }
}
