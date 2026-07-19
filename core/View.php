<?php

declare(strict_types=1);

namespace CodeVault;

use CodeVault\Theme\ThemeSettings;
use RuntimeException;

/**
 * Plain-PHP view layer (no templating DSL) — renders a `.php` file from
 * resources/views with `$data` extracted into scope. `e()` is the one
 * mandatory habit: never echo unescaped output in a template.
 */
class View
{
    public function __construct(
        private readonly string $viewsPath,
        private readonly ?ThemeSettings $theme = null
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        $path = $this->viewsPath . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($path)) {
            throw new RuntimeException("View [{$template}] not found at [{$path}].");
        }

        // Every template gets $view for free, so layouts can pull in
        // partials (header/footer) without the caller wiring it through.
        // 'theme' rides the same mechanism — every controller gets branding
        // in its layout without threading ThemeSettings through 20+ page()
        // helpers; a caller-supplied 'theme' key still wins.
        $data += ['view' => $this];

        if ($this->theme !== null) {
            try {
                $data += ['theme' => $this->theme->get()];
            } catch (\Throwable) {
                $data += ['theme' => [
                    'brandName' => 'CodeVault',
                    'logoUrl' => null,
                    'primaryColor' => '#2f6fed',
                    'primaryColorDark' => '#2558c4',
                ]];
            }
        }

        $renderer = function (string $__path, array $__data) {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__path;

            return ob_get_clean();
        };

        return $renderer($path, $data) ?: '';
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }
}

