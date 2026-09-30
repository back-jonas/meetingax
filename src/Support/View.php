<?php

declare(strict_types=1);

namespace Meetingax\Support;

/**
 * Enkla PHP-mallar. Dynamiska värden ska escapas med e() i mallen.
 */
final class View
{
    private array $shared = [];

    public function __construct(private readonly string $root)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = [], string $layout = 'layout/admin.php'): void
    {
        $content = $this->capture($template, $data);
        echo $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    public function partial(string $template, array $data = []): void
    {
        echo $this->capture($template, $data);
    }

    private function capture(string $template, array $data): string
    {
        $file = $this->root . '/' . $template;
        if (!is_file($file)) {
            throw new \RuntimeException('Mall saknas.');
        }
        $shared = $this->shared;
        extract($shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
