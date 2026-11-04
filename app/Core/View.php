<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = [], string $layout = 'layouts.app'): void
    {
        $viewFile = self::resolve($view);

        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $viewFile;
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        $content = (string) ob_get_clean();

        if ($layout === '') {
            echo $content;
            return;
        }

        $layoutFile = self::resolve($layout);

        ob_start();
        try {
            require $layoutFile;
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        echo (string) ob_get_clean();
    }

    private static function resolve(string $name): string
    {
        $relative = str_replace('.', '/', $name);
        $file = BASE_PATH . '/app/Views/' . $relative . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View tidak ditemukan: ' . $name);
        }

        return $file;
    }
}
