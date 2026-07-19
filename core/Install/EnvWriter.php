<?php

declare(strict_types=1);

namespace CodeVault\Install;

/**
 * Writes `.env` from `.env.example` as a template, overriding only the
 * keys the installer collected — everything else keeps its documented
 * default instead of the installer having to know every setting that exists.
 */
final class EnvWriter
{
    /**
     * @param array<string, string> $values
     */
    public static function write(string $examplePath, string $targetPath, array $values): void
    {
        $lines = is_file($examplePath)
            ? file($examplePath, FILE_IGNORE_NEW_LINES)
            : [];

        $lines = $lines === false ? [] : $lines;
        $written = [];
        $output = [];

        foreach ($lines as $line) {
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=/', $line, $m)) {
                $key = $m[1];

                if (array_key_exists($key, $values)) {
                    $output[] = "{$key}={$values[$key]}";
                    $written[$key] = true;
                    continue;
                }
            }

            $output[] = $line;
        }

        foreach ($values as $key => $value) {
            if (!isset($written[$key])) {
                $output[] = "{$key}={$value}";
            }
        }

        file_put_contents($targetPath, implode("\n", $output) . "\n");
    }

    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }
}
