<?php

declare(strict_types=1);

namespace Meetingax\Domain;

use Meetingax\Support\Validator;
use PDO;
use PDOException;

final class UserService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(string $name, string $email, string $password): int
    {
        $nameError = Validator::name($name, 120, 'Namnet');
        if ($nameError !== null) {
            throw new AppException('VALIDATION', $nameError);
        }
        $emailError = Validator::email($email);
        if ($emailError !== null) {
            throw new AppException('VALIDATION', $emailError);
        }
        $passwordError = Validator::password($password);
        if ($passwordError !== null) {
            throw new AppException('VALIDATION', $passwordError);
        }
        $email = Validator::normalizeEmail($email);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([trim($name), $email, $hash]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) {
                throw new AppException('DUPLICATE_EMAIL', 'E-postadressen används redan.');
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }
}
