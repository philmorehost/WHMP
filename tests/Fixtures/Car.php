<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

class Car
{
    public function __construct(
        public readonly EngineInterface $engine,
        public readonly string $model = 'unnamed'
    ) {
    }
}
