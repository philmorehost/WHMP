<?php

declare(strict_types=1);

namespace CodeVault\Fraud;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\PiiRedactor;
use CodeVault\Modules\FraudModule;

/**
 * AI triage (blueprint §4.4 "MaxMind minFraud + custom rules + AI
 * triage"). Asks the shared AiProvider to reason about order-shaped
 * signals a fixed rule set can't easily encode (e.g. a mismatched-
 * sounding name/email, an implausible order composition) and return a
 * score + short reasons. Fails open — any AI-side error (missing key,
 * network, bad response) scores 0/no-hold rather than blocking a real
 * order because a third party had an outage; RuleBasedFraudModule still
 * catches the deterministic cases on its own.
 */
final class DeepSeekFraudTriageModule implements FraudModule
{
    public function __construct(
        private readonly AiProvider $ai
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'AI Fraud Triage',
            'description' => 'Asks the configured AI provider to flag orders that look risky in ways fixed rules miss.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function score(array $order): array
    {
        $systemPrompt = 'You are a fraud-triage assistant for a web hosting company\'s order system. '
            . 'Given an order summary, respond with ONLY a JSON object of the exact shape '
            . '{"score": <0-100 number>, "reasons": [<short strings>]} — no other text. '
            . 'Score reflects how risky/fraudulent the order looks; 0 means no concern.';

        $result = $this->ai->complete($systemPrompt, $this->summarize($order));

        if (!$result['success'] || $result['text'] === null) {
            return ['score' => 0.0, 'hold' => false, 'reasons' => []];
        }

        $parsed = json_decode($result['text'], true);

        if (!is_array($parsed) || !isset($parsed['score'])) {
            return ['score' => 0.0, 'hold' => false, 'reasons' => []];
        }

        $score = max(0.0, min(100.0, (float) $parsed['score']));
        $reasons = is_array($parsed['reasons'] ?? null)
            ? array_map(static fn ($reason) => (string) $reason, $parsed['reasons'])
            : [];

        return ['score' => $score, 'hold' => $score >= 50.0, 'reasons' => $reasons];
    }

    private function summarize(array $order): string
    {
        $lines = [
            'Order total: $' . number_format((float) ($order['total'] ?? 0), 2),
            'Client name: ' . PiiRedactor::redact((string) ($order['clientName'] ?? '')),
            'Client email domain: ' . $this->emailDomain((string) ($order['clientEmail'] ?? '')),
            'Client account age (minutes): ' . (string) ($order['clientAccountAgeMinutes'] ?? 'unknown'),
        ];

        foreach (($order['items'] ?? []) as $item) {
            $lines[] = sprintf('Item: %s x%d @ $%.2f', (string) ($item['product_name'] ?? ''), (int) ($item['quantity'] ?? 1), (float) ($item['unit_price'] ?? 0));
        }

        return implode("\n", $lines);
    }

    private function emailDomain(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'unknown';
    }
}
