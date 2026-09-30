<?php

declare(strict_types=1);

namespace Meetingax\Http;

use Meetingax\Domain\AppException;

final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method) {
                continue;
            }
            $names = [];
            $regex = preg_replace_callback('/\{([a-zA-Z]+)\}/', static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '([^/]+)';
            }, $pattern);
            if (!is_string($regex)) {
                continue;
            }
            if (preg_match('#^' . $regex . '$#', $request->path, $matches) !== 1) {
                continue;
            }
            $params = [];
            foreach ($names as $index => $name) {
                $params[$name] = $matches[$index + 1] ?? '';
            }
            $handler($params);
            return;
        }
        throw new AppException('NOT_FOUND', 'Sidan hittades inte.', 404);
    }
}
