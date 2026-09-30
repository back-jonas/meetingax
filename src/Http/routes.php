<?php

declare(strict_types=1);

use Meetingax\App;
use Meetingax\Http\Controllers\ApiController;
use Meetingax\Http\Controllers\AuthController;
use Meetingax\Http\Controllers\DashboardController;
use Meetingax\Http\Controllers\HomeController;
use Meetingax\Http\Controllers\JoinController;
use Meetingax\Http\Controllers\MeetingController;
use Meetingax\Http\Controllers\ParticipantPageController;
use Meetingax\Http\Router;

return static function (App $app): Router {
    $router = new Router();

    $router->get('/', static function () use ($app): void {
        (new HomeController($app))->index();
    });
    $router->get('/login', static function () use ($app): void {
        (new AuthController($app))->loginForm();
    });
    $router->post('/login', static function () use ($app): void {
        (new AuthController($app))->login();
    });
    $router->post('/logout', static function () use ($app): void {
        (new AuthController($app))->logout();
    });
    $router->get('/register', static function () use ($app): void {
        (new AuthController($app))->registerForm();
    });
    $router->post('/register', static function () use ($app): void {
        (new AuthController($app))->register();
    });
    $router->get('/join', static function () use ($app): void {
        (new JoinController($app))->form();
    });
    $router->post('/join', static function () use ($app): void {
        (new JoinController($app))->submit();
    });
    $router->get('/dashboard', static function () use ($app): void {
        (new DashboardController($app))->index();
    });

    $router->get('/meeting/create', static function () use ($app): void {
        (new MeetingController($app))->createForm();
    });
    $router->post('/meeting/create', static function () use ($app): void {
        (new MeetingController($app))->create();
    });
    $router->get('/meeting/{publicId}', static function (array $params) use ($app): void {
        (new MeetingController($app))->show($params['publicId']);
    });
    $router->post('/meeting/{publicId}/status', static function (array $params) use ($app): void {
        (new MeetingController($app))->updateStatus($params['publicId']);
    });
    $router->post('/meeting/{publicId}/fields', static function (array $params) use ($app): void {
        (new MeetingController($app))->addField($params['publicId']);
    });
    $router->get('/meeting/{publicId}/participants', static function (array $params) use ($app): void {
        (new MeetingController($app))->participants($params['publicId']);
    });
    $router->post('/meeting/{publicId}/participants/{participantPublicId}', static function (array $params) use ($app): void {
        (new MeetingController($app))->participantAction($params['publicId'], $params['participantPublicId']);
    });
    $router->get('/meeting/{publicId}/votes', static function (array $params) use ($app): void {
        (new MeetingController($app))->polls($params['publicId']);
    });
    $router->post('/meeting/{publicId}/votes', static function (array $params) use ($app): void {
        (new MeetingController($app))->createPoll($params['publicId']);
    });
    $router->post('/meeting/{publicId}/votes/{pollPublicId}', static function (array $params) use ($app): void {
        (new MeetingController($app))->pollAction($params['publicId'], $params['pollPublicId']);
    });

    $router->get('/m/{code}', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->entry($params['code']);
    });
    $router->get('/m/{code}/register', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->registerForm($params['code']);
    });
    $router->post('/m/{code}/register', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->register($params['code']);
    });
    $router->get('/m/{code}/waiting', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->waiting($params['code']);
    });
    $router->get('/m/{code}/vote', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->vote($params['code']);
    });
    $router->post('/m/{code}/vote', static function (array $params) use ($app): void {
        (new ParticipantPageController($app))->cast($params['code']);
    });

    $router->get('/api/meeting/status', static function () use ($app): void {
        (new ApiController($app))->meetingStatus();
    });
    $router->get('/api/participant/status', static function () use ($app): void {
        (new ApiController($app))->participantStatus();
    });
    $router->get('/api/participant/state', static function () use ($app): void {
        (new ApiController($app))->participantState();
    });
    $router->get('/api/vote/current', static function () use ($app): void {
        (new ApiController($app))->voteCurrent();
    });
    $router->post('/api/vote/submit', static function () use ($app): void {
        (new ApiController($app))->voteSubmit();
    });
    $router->get('/api/admin/meeting/state', static function () use ($app): void {
        (new ApiController($app))->adminState();
    });
    $router->post('/api/admin/participant/approve', static function () use ($app): void {
        (new ApiController($app))->participantCommand('approve');
    });
    $router->post('/api/admin/participant/reject', static function () use ($app): void {
        (new ApiController($app))->participantCommand('reject');
    });
    $router->post('/api/admin/participant/remove', static function () use ($app): void {
        (new ApiController($app))->participantCommand('remove');
    });
    $router->post('/api/admin/participant/grant-vote', static function () use ($app): void {
        (new ApiController($app))->participantCommand('grant');
    });
    $router->post('/api/admin/participant/revoke-vote', static function () use ($app): void {
        (new ApiController($app))->participantCommand('revoke');
    });
    $router->post('/api/admin/vote/open', static function () use ($app): void {
        (new ApiController($app))->pollCommand('open');
    });
    $router->post('/api/admin/vote/close', static function () use ($app): void {
        (new ApiController($app))->pollCommand('close');
    });

    return $router;
};
