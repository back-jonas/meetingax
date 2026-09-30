<?php

declare(strict_types=1);

namespace Meetingax\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        private readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        if (str_contains($path, "\0")) {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }
        $body = $_POST;
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && strlen($raw) <= 20000) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }
        return new self($method, $path, $_GET, is_array($body) ? $body : [], self::headers());
    }

    public function input(string $key): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function inputArray(string $key): array
    {
        $value = $this->body[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    private static function headers(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
        return $headers;
    }
}
