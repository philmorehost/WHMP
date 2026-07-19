<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Alternative identity-verification methods offered on the client-area
 * Security tab / password-reset flow (e.g. a custom knowledge-based check),
 * independent of the primary password + 2FA path.
 */
interface SecurityQuestionModule extends Module
{
    public function prompt(int $clientId): string;

    public function verify(int $clientId, string $answer): bool;
}
