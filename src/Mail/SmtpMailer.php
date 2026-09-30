<?php

declare(strict_types=1);

namespace Meetingax\Mail;

use Meetingax\Domain\AppException;
use RuntimeException;
use Throwable;

/**
 * Skickar ett vanligt textmejl via SMTP. Fel mot servern visas inte för deltagaren.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(private readonly array $config)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        $smtp = is_array($this->config['smtp'] ?? null) ? $this->config['smtp'] : [];
        $host = (string) ($smtp['host'] ?? '');
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = (string) ($smtp['encryption'] ?? 'tls');
        $username = (string) ($smtp['username'] ?? '');
        $password = (string) ($smtp['password'] ?? '');
        $from = (string) ($this->config['from_address'] ?? '');
        $fromName = (string) ($this->config['from_name'] ?? 'Meetingax');
        if (
            !preg_match('/^[A-Za-z0-9.-]+$/', $host)
            || $port < 1
            || $port > 65535
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || !in_array($encryption, ['tls', 'ssl', 'none'], true)
        ) {
            throw new AppException('MAIL_FAILED', 'Mejlet kunde inte skickas.');
        }
        try {
            $this->transmit($host, $port, $encryption, $username, $password, $from, $fromName, $to, $subject, $text);
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('SMTP-fel: ' . $e->getMessage());
            throw new AppException('MAIL_FAILED', 'Mejlet kunde inte skickas.');
        }
    }

    private function transmit(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $text,
    ): void {
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('Kunde inte ansluta till mejlservern.');
        }
        stream_set_timeout($socket, 10);
        try {
            $this->expect($socket, '220');
            $this->command($socket, 'EHLO meetingax', '250');
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', '220');
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new RuntimeException('Kunde inte starta TLS mot mejlservern.');
                }
                $this->command($socket, 'EHLO meetingax', '250');
            }
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', '334');
                $this->command($socket, base64_encode($username), '334');
                $this->command($socket, base64_encode($password), '235');
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', '250');
            $this->command($socket, 'RCPT TO:<' . $to . '>', '250');
            $this->command($socket, 'DATA', '354');
            $payload = $this->message($from, $fromName, $to, $subject, $text);
            fwrite($socket, $payload . "\r\n.\r\n");
            $this->expect($socket, '250');
            $this->command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    private function message(string $from, string $fromName, string $to, string $subject, string $text): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $body = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
            $body[] = $line;
        }
        $headers = [
            'From: ' . $this->encoded($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encoded($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    private function encoded(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @param resource $socket */
    private function command($socket, string $command, string $code): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $code);
    }

    /** @param resource $socket */
    private function expect($socket, string $code): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '' || !str_starts_with($response, $code)) {
            throw new RuntimeException('Mejlservern avvisade utskicket.');
        }
    }
}
