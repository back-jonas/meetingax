<?php

declare(strict_types=1);

namespace Meetingax\Auth;

use Meetingax\Domain\AppException;
use Meetingax\Support\Validator;
use PDO;

/**
 * Permanent inloggning för mötesarrangörer. Sessionen lagrar bara användar-id.
 */
final class AdminAuth
{
    private const DUMMY_HASH = '$2y$10$DyMecAzfel8PC3Kx3ohhn.CO/YeW77H4mmaTDvpsOBztRRM/733Rq';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, created_at, last_login_at FROM users WHERE id = ?'
        );
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        return $user;
    }

    public function attempt(string $email, string $password): bool
    {
        $email = Validator::normalizeEmail($email);
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $hash = is_array($row) ? (string) $row['password_hash'] : self::DUMMY_HASH;
        $valid = password_verify($password, $hash);
        if (!$row || !$valid) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $row['id']]);
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user) {
            return $user;
        }
        $fetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' || isset($_GET['fragment']);
        if ($fetch) {
            throw new AppException('AUTH_REQUIRED', 'Du behöver logga in.', 401);
        }
        $next = $_SERVER['REQUEST_URI'] ?? '/dashboard';
        if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            $next = '/dashboard';
        }
        header('Location: /login?next=' . rawurlencode($next), true, 302);
        exit;
    }

    public function requireApi(): array
    {
        $user = $this->user();
        if (!$user) {
            throw new AppException('AUTH_REQUIRED', 'Du behöver logga in.', 401);
        }
        return $user;
    }
}
