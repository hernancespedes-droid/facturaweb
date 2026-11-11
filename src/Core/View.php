<?php
declare(strict_types=1);

namespace App\Core;

use App\App;
use RuntimeException;

/** Renderiza plantillas PHP de la carpeta views/ dentro de un layout. */
final class View
{
    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::capture(self::path($template), $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture(self::path($layout), $data + ['content' => $content]);
    }

    private static function path(string $template): string
    {
        $file = App::root() . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("No existe la vista: {$template}");
        }
        return $file;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
