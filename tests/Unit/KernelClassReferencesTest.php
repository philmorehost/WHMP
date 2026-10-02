<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `Foo::class` on a name that was never imported is a SILENT lie.
 *
 * PHP resolves it against the file's current namespace rather than failing, so
 * `ResellerRetailPricing::class` written in `namespace CodeVault` — with no
 * `use CodeVault\Reseller\ResellerRetailPricing;` — evaluates to the string
 * `"CodeVault\ResellerRetailPricing"`. Nothing complains at parse time, nothing
 * complains at `::class` time, and the typo surfaces much later as a container
 * failure:
 *
 *   RuntimeException: Cannot resolve unknown class [CodeVault\ResellerRetailPricing].
 *     Container.php:90 <- Kernel.php:507 (the CartService singleton)
 *
 * That is production, on the storefront, and it is in the same family as the
 * `CodeVault\Catalog\BillingCycle` vs `CodeVault\Billing\BillingCycle` fatal this
 * codebase already paid for once. A wrong namespace is the one bug PHP's own
 * machinery will never report, so it needs a test.
 *
 * WHAT THIS CHECKS
 *
 * Every `Name::class` in core/Kernel.php must name a class that actually exists —
 * after resolving imports and aliases the way PHP would. `self`, `static` and
 * `parent` are skipped. Both the bare form (`Foo::class`) and the fully qualified
 * form (`\Vendor\Thing::class`) are checked, because they can both be wrong.
 *
 * The Kernel is the right file to start with (it wires almost every service, and
 * a bad name there takes out a whole page), but the same scan is generic — widen
 * the file list if another file ever grows a `::class` reference.
 *
 * Verified red: with the missing `use` statement in place this fails with
 *   Kernel.php references ResellerRetailPricing::class but CodeVault\ResellerRetailPricing
 *   does not exist — add a `use CodeVault\Reseller\ResellerRetailPricing;`.
 */
final class KernelClassReferencesTest extends TestCase
{
    /** Keywords that are never class references. */
    private const KEYWORDS = ['self', 'static', 'parent'];

    public function test_every_class_constant_in_the_kernel_resolves(): void
    {
        $path = dirname(__DIR__, 2) . '/core/Kernel.php';
        $source = (string) file_get_contents($path);

        $namespace = $this->currentNamespace($source);
        $imports = $this->imports($source);

        $checked = 0;
        $problems = [];

        foreach ($this->references($source) as $reference) {
            [$raw, $bare] = $reference;

            if ($bare !== null && in_array($bare, self::KEYWORDS, true)) {
                continue;
            }

            // Resolve the way PHP would: an import/alias wins, otherwise the
            // name is relative to this file's namespace (or is already absolute).
            if ($bare !== null) {
                $resolved = $imports[$bare] ?? $namespace . '\\' . $bare;
            } else {
                $resolved = ltrim($raw, '\\');
            }

            $checked++;

            if (class_exists($resolved) || interface_exists($resolved) || enum_exists($resolved)) {
                continue;
            }

            $problems[] = $bare === null
                ? "Kernel.php references \\{$resolved}::class, which does not exist."
                : "Kernel.php references {$bare}::class but {$resolved} does not exist — "
                    . "add a `use {$resolved};` (or a correct one).";
        }

        $this->assertGreaterThan(50, $checked, 'the scan should find many ::class references — the regex has stopped matching');
        $this->assertSame([], array_values(array_unique($problems)), implode("\n", array_unique($problems)));
    }

    private function currentNamespace(string $source): string
    {
        preg_match('/^namespace\s+([^;]+);/m', $source, $m);

        return trim($m[1] ?? '');
    }

    /**
     * Long name => short name, honouring `as` aliases. `use function`/`use const`
     * lines are ignored: they do not create class names.
     *
     * @return array<string, string>
     */
    private function imports(string $source): array
    {
        preg_match_all('/^use\s+(?!function\s|const\s)([\\\\\w]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        $imports = [];

        foreach ($matches as $match) {
            $fqcn = ltrim($match[1], '\\');
            // `?? ''` matters: an unaliased `use` has no match[2] at all, and
            // `null !== ''` is TRUE — so testing $match[2] directly would file
            // every unaliased import under the empty string and resolve nothing.
            $alias = $match[2] ?? '';
            $short = $alias !== '' ? $alias : substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

            $imports[$short] = $fqcn;
        }

        return $imports;
    }

    /**
     * @return array<int, array{0: string, 1: string|null}> [raw name, bare name or null when fully qualified]
     */
    private function references(string $source): array
    {
        $found = [];

        // \Vendor\Thing::class — already absolute.
        preg_match_all('/(\\\\[A-Z]\w*(?:\\\\[A-Z]\w*)+)::class/', $source, $qualified);
        foreach ($qualified[1] as $name) {
            $found[] = [$name, null];
        }

        // Thing::class — NOT preceded by a backslash, word char, `$` or `:` so
        // the tail of a qualified name is not counted twice.
        preg_match_all('/(?<![\w\\\\$:])([A-Z]\w*)::class/', $source, $bare);
        foreach ($bare[1] as $name) {
            $found[] = [$name, $name];
        }

        return $found;
    }
}
