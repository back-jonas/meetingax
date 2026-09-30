<?php

declare(strict_types=1);

namespace Meetingax\Http\Controllers;

use Meetingax\Auth\RateLimiter;
use Meetingax\Domain\AppException;
use Meetingax\Domain\UserService;
use Meetingax\Http\Controller;
use Meetingax\Support\Flash;

/** Konto för mötesarrangörer. */
final class AuthController extends Controller
{
    public function loginForm(?string $error = null): void
    {
        if ($this->app->admin->user()) {
            $this->redirect('/dashboard');
        }
        $this->app->view->render('auth/login.php', [
            'title' => 'Logga in',
            'error' => $error,
            'next' => $this->safeNext($this->app->request->input('next')),
        ], 'layout/guest.php');
    }

    public function login(): void
    {
        $this->assertCsrf();
        if ($this->app->admin->user()) {
            $this->redirect('/dashboard');
        }
        if (!$this->app->rates->allow('login', RateLimiter::clientIp(), 30, 900)) {
            $this->loginForm('För många försök. Vänta en stund och försök igen.');
            return;
        }
        $email = $this->app->request->input('email');
        $password = $this->app->request->input('password');
        if (!$this->app->admin->attempt($email, $password)) {
            $this->loginForm('E-postadressen eller lösenordet stämmer inte.');
            return;
        }
        $this->redirect($this->safeNext($this->app->request->input('next')));
    }

    public function logout(): void
    {
        $this->assertCsrf();
        $this->app->admin->logout();
        $this->redirect('/login');
    }

    public function registerForm(?string $error = null): void
    {
        if ($this->app->admin->user()) {
            $this->redirect('/dashboard');
        }
        $this->app->view->render('auth/register.php', [
            'title' => 'Skapa konto',
            'error' => $error,
            'name' => $this->app->request->input('name'),
            'email' => $this->app->request->input('email'),
        ], 'layout/guest.php');
    }

    public function register(): void
    {
        $this->assertCsrf();
        if (!$this->app->rates->allow('account-register', RateLimiter::clientIp(), 10, 3600)) {
            $this->registerForm('För många försök. Vänta en stund och försök igen.');
            return;
        }
        $password = $this->app->request->input('password');
        if ($password !== $this->app->request->input('password_confirmation')) {
            $this->registerForm('Lösenorden matchar inte.');
            return;
        }
        try {
            (new UserService($this->app->pdo))->register(
                $this->app->request->input('name'),
                $this->app->request->input('email'),
                $password
            );
        } catch (AppException $e) {
            $this->registerForm($e->getMessage());
            return;
        }
        $this->app->admin->attempt($this->app->request->input('email'), $password);
        Flash::set('ok', 'Kontot är skapat.');
        $this->redirect('/dashboard');
    }
}
