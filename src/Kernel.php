<?php

declare(strict_types=1);

namespace Meetingax;

use Meetingax\Auth\AdminAuth;
use Meetingax\Auth\ParticipantAuth;
use Meetingax\Auth\RateLimiter;
use Meetingax\Database\Connection;
use Meetingax\Domain\AppException;
use Meetingax\Http\Csrf;
use Meetingax\Http\Json;
use Meetingax\Http\Request;
use Meetingax\Support\Flash;
use Meetingax\Support\View;
use PDOException;
use Throwable;

/**
 * Startar ett webb-anrop och ser till att oväntade fel inte läcker detaljer.
 */
final class Kernel
{
    public static function run(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        date_default_timezone_set('UTC');
        self::securityHeaders();

        try {
            $config = meetingax_config();
        } catch (\RuntimeException) {
            self::plain(500, 'Appen är inte konfigurerad. Kopiera config/config.example.php till config/config.php.');
            return;
        }

        self::startSession($config);
        $request = Request::fromGlobals();

        try {
            $pdo = Connection::make($config['db']);
            $view = new View(dirname(__DIR__) . '/templates');
            $app = new App(
                $config,
                $pdo,
                $request,
                $view,
                new AdminAuth($pdo),
                new ParticipantAuth($pdo, $config),
                new RateLimiter($pdo),
            );
            $view->share('flash', Flash::pull());
            $view->share('csrf', Csrf::token());
            $view->share('adminUser', $app->admin->user());
            $view->share('appName', 'Meetingax');
            ob_start();
            (require dirname(__DIR__) . '/src/Http/routes.php')($app)->dispatch($request);
            ob_end_flush();
        } catch (AppException $e) {
            self::appError($request, $e);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            self::unexpected($request);
        } catch (Throwable $e) {
            error_log($e->__toString());
            self::unexpected($request);
        }
    }

    private static function startSession(array $config): void
    {
        $secure = (bool) ($config['session']['secure'] ?? false);
        session_name((string) ($config['session']['name'] ?? 'MX_SESSION'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }

    private static function securityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
        header('Cache-Control: no-store, private');
    }

    private static function appError(Request $request, AppException $e): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (str_starts_with($request->path, '/api/')) {
            Json::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        self::plain($e->httpStatus, $e->getMessage());
    }

    private static function unexpected(Request $request): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (str_starts_with($request->path, '/api/')) {
            Json::error('SERVER_ERROR', 'Ett oväntat fel inträffade.', 500);
        }
        self::plain(500, 'Ett oväntat fel inträffade.');
    }

    private static function plain(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="sv"><meta charset="utf-8"><title>Meetingax</title><p>'
            . e($message)
            . '</p><p><a href="' . e(url('/')) . '">Till startsidan</a></p></html>';
    }
}
