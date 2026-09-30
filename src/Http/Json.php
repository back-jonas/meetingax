<?php

declare(strict_types=1);

namespace Meetingax\Http;

final class Json
{
    public static function ok(mixed $data, int $status = 200): never
    {
        self::send([
            'success' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    public static function error(string $code, string $message, int $status): never
    {
        self::send([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    private static function send(array $payload, int $status): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
        exit;
    }
}
