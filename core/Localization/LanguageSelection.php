<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Session\SessionManager;

/**
 * The visitor's in-session language choice — mirrors CurrencySelection's
 * pattern exactly, so a guest can switch language before they have an
 * account, and LocalizationService::resolveEffective() reads this.
 */
final class LanguageSelection
{
    private const SESSION_KEY = 'language_id';

    public function __construct(
        private readonly SessionManager $session
    ) {
    }

    public function get(): ?int
    {
        $value = $this->session->get(self::SESSION_KEY);

        return $value !== null ? (int) $value : null;
    }

    public function set(int $languageId): void
    {
        $this->session->set(self::SESSION_KEY, $languageId);
    }
}
