<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Ai\AiProvider;

final class FakeAiProvider implements AiProvider
{
    /** @var array{success: bool, text: ?string, error: ?string} */
    private array $response;

    /** @var array<int, array{system: string, user: string}> */
    public array $calls = [];

    public function __construct(bool $success = true, ?string $text = '', ?string $error = null)
    {
        $this->response = ['success' => $success, 'text' => $text, 'error' => $error];
    }

    public function respondWith(bool $success, ?string $text, ?string $error = null): void
    {
        $this->response = ['success' => $success, 'text' => $text, 'error' => $error];
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        $this->calls[] = ['system' => $systemPrompt, 'user' => $userPrompt];

        return $this->response;
    }

    /** @return array{system: string, user: string}|null */
    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}
