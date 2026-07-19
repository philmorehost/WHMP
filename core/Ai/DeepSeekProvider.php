<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Provisioning\HttpClient;

/**
 * DeepSeek's chat completions API is OpenAI-compatible
 * (POST /chat/completions, {model, messages, temperature}), so this is a
 * thin adapter rather than a bespoke protocol implementation.
 */
final class DeepSeekProvider implements AiProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey,
        private readonly string $model = 'deepseek-chat',
        private readonly string $baseUrl = 'https://api.deepseek.com'
    ) {
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'text' => null, 'error' => 'DeepSeek API key is not configured.'];
        }

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.4,
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->http->request('POST', $this->baseUrl . '/chat/completions', [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ], (string) $payload);

        if ($response['status'] !== 200) {
            return ['success' => false, 'text' => null, 'error' => "DeepSeek API returned HTTP {$response['status']}."];
        }

        $decoded = json_decode($response['body'], true);
        $text = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;

        if (!is_string($text) || trim($text) === '') {
            return ['success' => false, 'text' => null, 'error' => 'DeepSeek API returned an empty response.'];
        }

        return ['success' => true, 'text' => trim($text), 'error' => null];
    }
}
