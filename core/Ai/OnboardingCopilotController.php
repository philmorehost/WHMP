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

        $question = trim((string) $request->input('question', ''));

        if ($question === '') {
            return Response::json(['success' => false, 'answer' => null, 'message' => 'Please type a question.']);
        }

        if (strlen($question) > 1000) {
            $question = substr($question, 0, 1000);
        }

        if (!$this->settings->isFeatureEnabled('onboarding') || !$this->settings->hasKey()) {
            $fallback = $this->getFallbackAnswer($question);
            return Response::json(['success' => true, 'answer' => $fallback, 'message' => null]);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT, $question);

        if (($result['success'] ?? false) !== true) {
            $fallback = $this->getFallbackAnswer($question);
            return Response::json(['success' => true, 'answer' => $fallback, 'message' => null]);
        }

        return Response::json(['success' => true, 'answer' => $result['text'], 'message' => null]);
    }

    private function getFallbackAnswer(string $question): string
    {
        $q = strtolower($question);

        if (str_contains($q, 'cpanel') || str_contains($q, 'login') || str_contains($q, 'log in')) {
            return "To log into cPanel:\n1. Go to 'Services' -> click on your Active Hosting Package.\n2. Click the 'Log into cPanel' button under Quick Actions.\n3. Alternatively, use your welcome email credentials at yourdomain.com:2083.";
        }

        if (str_contains($q, 'point') || str_contains($q, 'nameserver') || str_contains($q, 'dns')) {
            return "To point your domain to your hosting:\n1. Navigate to 'Domains' -> 'My Domains'.\n2. Select your domain and click 'Manage Nameservers'.\n3. Update your nameservers to the ones listed in your Hosting Welcome Email (e.g. ns1.yourhost.com & ns2.yourhost.com).";
        }

        if (str_contains($q, 'online') || str_contains($q, 'pay') || str_contains($q, 'after payment') || str_contains($q, 'order')) {
            return "What happens after payment:\n1. Your hosting package & domain order are automatically provisioned.\n2. An invoice receipt & Welcome Email containing your cPanel/FTP credentials will be sent to your email address.\n3. You can manage your new service immediately under 'Services' -> 'Active Services'.";
        }

        return "Here is a quick guide:\n- **Services & Hosting:** Manage active packages under the 'Services' menu.\n- **Domains:** Register or point domains under the 'Domains' menu.\n- **Billing & Invoices:** View and pay invoices under 'Billing'.\n- **Support:** Need human assistance? Open a support ticket under 'Support'.";
    }
}
