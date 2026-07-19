<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Dashboard widgets (Admin → Dashboard, blueprint §4.3).
 */
interface WidgetModule extends Module
{
    /**
     * Where it's allowed to render.
     */
    public function placement(): string;

    public function render(): string;
}
