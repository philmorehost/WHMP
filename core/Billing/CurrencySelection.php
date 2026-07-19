<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Session\SessionManager;

/**
 * The visitor's in-session currency choice (mirrors Cart's session-backed
 * pattern) — lets a guest switch display currency on the store before
 * they have an account. CurrencyService::resolveEffective() reads this.
 */
final class CurrencySelection
{
    private const SESSION_KEY = 'currency_id';

    public function __construct(
        private readonly SessionManager $session
    ) {
    }

    public function get(): ?int
    {
        $value = $this->session->get(self::SESSION_KEY);

        return $value !== null ? (int) $value : null;
    }

    public function set(int $currencyId): void
    {
        $this->session->set(self::SESSION_KEY, $currencyId);
    }
}
