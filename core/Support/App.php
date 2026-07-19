<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Container;
use RuntimeException;

/**
 * A narrow service-locator escape hatch, used ONLY where normal constructor
 * injection can't reach: queued Job payloads. A Job must be plain-data
 * serializable (blueprint §3 "Queue/worker") so it can cross a Redis-backed
 * queue into a separate worker process — it can't hold a PDO connection or
 * any other unserializable service as a property. `App::container()` lets
 * a Job resolve what it needs at `handle()` time, in whichever process
 * runs it. Do not reach for this anywhere a constructor dependency works.
 */
final class App
{
    private static ?Container $container = null;

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new RuntimeException('App container has not been set — Kernel must construct before any Job runs.');
        }

        return self::$container;
    }
}
