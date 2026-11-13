<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Simple template renderer with view paths and layout support.
 */
final class View
{
    private static array $paths = [];

    public static function addPath(string $path): void
    {
        self::$paths[] = rtrim($path, '/');
    }

    public static function render(string $template, array $data = []): string
    {
        $file = self::locate($template);
        if ($file === null) {
            throw new \RuntimeException('View tidak ditemukan: ' . $template);
        }
        $content = self::evaluate($file, $data);
        if (isset($data['layout']) && $data['layout'] !== null) {
            $layoutFile = self::locate((string)$data['layout']);
            if ($layoutFile === null) {
                throw new \RuntimeException('Layout tidak ditemukan: ' . $data['layout']);
            }
            return self::evaluate($layoutFile, $data + ['content' => $content]);
        }
        return $content;
    }

    public static function exists(string $template): bool
    {
        return self::locate($template) !== null;
    }

    public static function evaluate(string $file, array $data): string
    {
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string)ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($file, $data);
    }

    private static function locate(string $template): ?string
    {
        $template = str_replace(['..', '\\'], ['', '/'], $template);
        $candidates = [];
        foreach (self::$paths as $path) {
            $candidates[] = $path . '/' . $template . '.php';
            $candidates[] = $path . '/' . $template;
        }
        $candidates[] = CMS_ROOT . '/app/Views/' . $template . '.php';
        $candidates[] = CMS_ROOT . '/app/Views/' . $template;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

View::addPath(CMS_ROOT . '/app/Views');
View::addPath(CMS_ROOT . '/app/Views/front');
View::addPath(CMS_ROOT . '/app/Views/admin');
