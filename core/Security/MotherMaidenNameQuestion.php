<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Modules\ClientSecurityAnswerRepository;
use CodeVault\Modules\SecurityQuestionModule;

/**
 * The reference SecurityQuestionModule implementation (R28) — proves the
 * SDK end-to-end the same way TopClientsWidget/ServiceChurnReport did for
 * WidgetModule/ReportModule: a real, useful module rather than a
 * placeholder. A single static knowledge-based question, the classic
 * WHMCS-parity "security question" identity-verification factor.
 *
 * By the time SecurityQuestionModuleService::verify() delegates to this
 * class, it has already resolved *which* module a client configured via
 * `client_security_answers.module_slug` — this class only needs to check
 * the stored hash, not re-confirm it owns the client's answer.
 */
final class MotherMaidenNameQuestion implements SecurityQuestionModule
{
    public function __construct(
        private readonly ClientSecurityAnswerRepository $answers
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => "Mother's Maiden Name",
            'description' => "A single knowledge-based question — \"What is your mother's maiden name?\" — usable as an extra identity-verification step during password reset.",
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function prompt(int $clientId): string
    {
        return "What is your mother's maiden name?";
    }

    public function verify(int $clientId, string $answer): bool
    {
        $row = $this->answers->find($clientId);

        if ($row === null) {
            return false;
        }

        return password_verify(trim($answer), (string) $row['answer_hash']);
    }
}
