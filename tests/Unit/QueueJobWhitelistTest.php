<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Queue\RedisQueue;
use PHPUnit\Framework\TestCase;

/**
 * The queue unserializes Redis payloads with a class whitelist (RedisQueue::
 * ALLOWED_JOB_CLASSES) instead of allowed_classes => true — object injection
 * needs a gadget class, and the whitelist is the gadget inventory.
 *
 * The failure mode this test exists for: someone adds a new job class,
 * pushes it onto the queue, and the worker silently returns null for every
 * one of its payloads (unserialize refuses unlisted classes) — the job
 * never runs and nothing errors. Scanning the source for `implements Job`
 * the same way the ApiDocumentation test scans routes keeps the list from
 * drifting: a new job class without a whitelist entry FAILS HERE.
 */
final class QueueJobWhitelistTest extends TestCase
{
    public function test_every_job_class_is_whitelisted_for_unserialize(): void
    {
        $expected = $this->jobClassesIn(dirname(__DIR__, 2) . '/core');

        $this->assertNotEmpty($expected, 'the scan found no job classes — the scan itself is broken');

        sort($expected);
        $allowed = RedisQueue::ALLOWED_JOB_CLASSES;
        sort($allowed);

        $this->assertSame(
            $expected,
            array_values($allowed),
            'RedisQueue::ALLOWED_JOB_CLASSES is missing (or no longer matches) the job classes in core/'
        );

        foreach (RedisQueue::ALLOWED_JOB_CLASSES as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not exist (stale whitelist entry)");
        }
    }

    /** Every FQCN in $dir whose source says `implements Job` — Job being CodeVault\Queue\Job. */
    /** @return array<int, string> */
    private function jobClassesIn(string $dir): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ((string) $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents((string) $file->getPathname());

            if (preg_match('~^\s*(?:final )?class (\w+)(?: extends [\w\\\\]+)? implements (?:\\\\CodeVault\\\\Queue\\\\)?Job\b~m', $source, $class)
                && preg_match('~^namespace ([^;]+);~m', $source, $namespace)) {
                $found[] = $namespace[1] . '\\' . $class[1];
            }
        }

        return array_values(array_unique($found));
    }
}
