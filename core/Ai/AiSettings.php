<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Config;
use CodeVault\Settings\SettingsRepository;

/**
 * Admin-managed AI configuration (API key, provider, model, per-feature
 * on/off toggles) stored in the key/value settings table, so a non-technical
 * admin can manage the copilot from the AI dashboard rather than editing
 * .env. The API key falls back to the DEEPSEEK_API_KEY env var when the
 * admin hasn't set one, so existing deployments keep working unchanged.
 */
final class AiSettings
{
    private const KEY_API = 'ai.api_key';
    private const KEY_PROVIDER = 'ai.provider';
    private const KEY_MODEL = 'ai.model';

    /** Feature slugs the admin can toggle. */
    public const FEATURES = [
        'ticket_replies' => 'AI support ticket-reply drafting',
        'fraud' => 'AI fraud triage on new orders',
        'kb' => 'AI knowledgebase answers',
        'domain_suggestions' => 'AI domain-name suggestions',
        'onboarding' => 'Client onboarding copilot',
        'marketing_copilot' => 'Marketing campaign copilot (write / refine)',
        'promo_banner_copilot' => 'Promo banner copy generator',
        'kb_copilot' => 'Knowledgebase article/category writer + diagram generator',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Config $config
    ) {
    }

    public function apiKey(): string
    {
        $stored = trim((string) ($this->settings->get(self::KEY_API) ?? ''));

        return $stored !== '' ? $stored : (string) $this->config->env('DEEPSEEK_API_KEY', '');
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function provider(): string
    {
        return trim((string) ($this->settings->get(self::KEY_PROVIDER) ?? '')) ?: 'deepseek';
    }

    public function model(): string
    {
        return trim((string) ($this->settings->get(self::KEY_MODEL) ?? '')) ?: 'deepseek-chat';
    }

    /** Features default to ON so nothing that worked before this page existed silently turns off. */
    public function isFeatureEnabled(string $feature): bool
    {
        $value = $this->settings->get('ai.feature.' . $feature);

        return $value === null || $value === '1';
    }

    /**
     * @param array<int, string> $enabledFeatures slugs that should be ON
     */
    public function save(?string $apiKey, string $provider, string $model, array $enabledFeatures): void
    {
        // Empty key input means "leave the existing key untouched" — so an
        // admin can change the model/toggles without re-typing the secret.
        if ($apiKey !== null && trim($apiKey) !== '') {
            $this->settings->set(self::KEY_API, trim($apiKey));
        }

        $this->settings->set(self::KEY_PROVIDER, $provider !== '' ? $provider : 'deepseek');
        $this->settings->set(self::KEY_MODEL, $model !== '' ? $model : 'deepseek-chat');

        foreach (array_keys(self::FEATURES) as $feature) {
            $this->settings->set('ai.feature.' . $feature, in_array($feature, $enabledFeatures, true) ? '1' : '0');
        }
    }

    public function clearKey(): void
    {
        $this->settings->set(self::KEY_API, '');
    }

    /** A masked preview of the stored key for display (never the full secret). */
    public function maskedKey(): string
    {
        $key = $this->apiKey();

        if ($key === '') {
            return '';
        }

        $visible = substr($key, -4);

        return str_repeat('•', max(4, strlen($key) - 4)) . $visible;
    }
}
