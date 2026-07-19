<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

class V8Engine implements EngineInterface
{
    public function horsepower(): int
    {
        return 400;
    }
}
