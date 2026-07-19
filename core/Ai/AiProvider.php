<?php

declare(strict_types=1);

namespace CodeVault\Ai;

/**
 * The seam between every AI-assisted feature (ticket-reply drafting,
 * fraud triage, the admin Ask-AI helper, AI-assisted KB search) and
 * whichever LLM vendor answers — one generic chat-completion call so
 * swapping providers, or adding a second one, never touches a caller.
 */
interface AiProvider
{
    /** @return array{success: bool, text: ?string, error: ?string} */
    public function complete(string $systemPrompt, string $userPrompt): array;
}
