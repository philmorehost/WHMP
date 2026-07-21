<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;

/**
 * Client-area onboarding copilot: answers a newcomer's "what do I do now?"
 * questions — how to order hosting, what happens after payment, how to point
 * a domain, how to access cPanel — in plain language. Gated behind the
 * admin's "onboarding" AI toggle; declines gracefully when off or
 * unconfigured. Client-authenticated (it's part of the client dashboard).
 */
final class OnboardingCopilotController
{
    private const SYSTEM_PROMPT =
        "You are the onboarding assistant for a web hosting and domain company's client portal. "
        . "The person asking is a customer, often a beginner. Explain in clear, friendly, concrete steps how to: "
        . "order and pay for hosting, what happens right after payment (account provisioning), how to log into cPanel, "
        . "how to point or register a domain, upload a website, and set up email. "
        . "Keep answers short and practical. If a question is outside hosting/domains/billing, gently steer back. "
        . "Never invent account-specific details (passwords, IPs) — tell them where in the portal to find those instead.";

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly AiProvider $ai,
        private readonly AiSettings $settings
    ) {
    }

    public function ask(Request $request): Response
    {
        if ($this->guard->currentClient() === null) {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'Please log in to use the assistant.'], 401);
        }

        if (!$this->settings->isFeatureEnabled('onboarding')) {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'The onboarding assistant is currently unavailable.']);
        }

        if (!$this->settings->hasKey()) {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'The assistant is not configured yet — please contact support.']);
        }

        $question = trim((string) $request->input('question', ''));

        if ($question === '') {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'Please type a question.']);
        }

        if (strlen($question) > 1000) {
            $question = substr($question, 0, 1000);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT, $question);

        if (($result['success'] ?? false) !== true) {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'The assistant could not answer right now. Please try again shortly.']);
        }

        return Response::json(['success' => true, 'answer' => $result['text'], 'message' => null]);
    }
}
