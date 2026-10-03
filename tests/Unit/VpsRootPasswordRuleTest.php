<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\ClientServiceController;
use PHPUnit\Framework\TestCase;

/** The new root password a client chooses when reinstalling their VPS's OS. */
final class VpsRootPasswordRuleTest extends TestCase
{
    public function test_a_long_mixed_password_is_accepted(): void
    {
        $this->assertNull(ClientServiceController::rootPasswordProblem('Lagos2026root'));
        $this->assertNull(ClientServiceController::rootPasswordProblem('a1!@#$%^&*()-_=+'));
    }

    public function test_short_letters_only_or_digits_only_are_refused(): void
    {
        $this->assertNotNull(ClientServiceController::rootPasswordProblem('abc123'));
        $this->assertNotNull(ClientServiceController::rootPasswordProblem('onlyletterspassword'));
        $this->assertNotNull(ClientServiceController::rootPasswordProblem('12345678901234'));
    }

    public function test_spaces_quotes_and_backslashes_are_refused(): void
    {
        foreach (['Lagos 2026root', "Lagos2026'root", 'Lagos2026"root', 'Lagos2026\\root', str_repeat('a1', 33)] as $bad) {
            $this->assertNotNull(ClientServiceController::rootPasswordProblem($bad), $bad);
        }
    }
}
