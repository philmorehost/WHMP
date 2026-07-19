<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Ai\DeepSeekProvider;
use CodeVault\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class DeepSeekProviderTest extends TestCase
{
    private FakeHttpClient $http;
    private DeepSeekProvider $provider;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->provider = new DeepSeekProvider($this->http, 'test-api-key', 'deepseek-chat');
    }

    public function test_posts_to_the_documented_chat_completions_endpoint_with_bearer_auth(): void
    {
        $this->http->respondWith(200, '{"choices":[{"message":{"content":"Sure, here is how to fix that."}}]}');

        $this->provider->complete('You are a helpful assistant.', 'How do I fix this?');

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $request['url']);
        $this->assertSame('Bearer test-api-key', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);

        $payload = json_decode((string) $request['body'], true);
        $this->assertSame('deepseek-chat', $payload['model']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('You are a helpful assistant.', $payload['messages'][0]['content']);
        $this->assertSame('user', $payload['messages'][1]['role']);
        $this->assertSame('How do I fix this?', $payload['messages'][1]['content']);
    }

    public function test_returns_the_completion_text_on_success(): void
    {
        $this->http->respondWith(200, '{"choices":[{"message":{"content":"Try restarting your router."}}]}');

        $result = $this->provider->complete('system', 'My internet is slow.');

        $this->assertTrue($result['success']);
        $this->assertSame('Try restarting your router.', $result['text']);
        $this->assertNull($result['error']);
    }

    public function test_missing_api_key_fails_without_making_a_request(): void
    {
        $provider = new DeepSeekProvider($this->http, '');

        $result = $provider->complete('system', 'Hi');

        $this->assertFalse($result['success']);
        $this->assertNull($this->http->lastRequest());
    }

    public function test_non_200_response_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(401, '{"error":"Unauthorized"}');

        $result = $this->provider->complete('system', 'Hi');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['error']);
    }

    public function test_unreachable_api_reports_failure_without_throwing(): void
    {
        $this->http->respondWith(0, '');

        $result = $this->provider->complete('system', 'Hi');

        $this->assertFalse($result['success']);
    }

    public function test_empty_completion_text_is_treated_as_failure(): void
    {
        $this->http->respondWith(200, '{"choices":[{"message":{"content":"   "}}]}');

        $result = $this->provider->complete('system', 'Hi');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }
}
