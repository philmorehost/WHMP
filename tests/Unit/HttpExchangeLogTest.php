<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\HttpExchangeLog;
use PHPUnit\Framework\TestCase;

final class HttpExchangeLogTest extends TestCase
{
    private const KEY = 'RQ7yABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnop';

    public function test_nothing_is_recorded_unless_switched_on(): void
    {
        HttpExchangeLog::record('GET', 'https://x.test/', [], null, 200, 'ok', '', [], []);
        HttpExchangeLog::start();

        $this->assertSame([], HttpExchangeLog::stop());
    }

    public function test_the_exchange_is_recorded_with_secrets_hidden_everywhere(): void
    {
        HttpExchangeLog::start();
        HttpExchangeLog::record(
            'get',
            'https://user:pw@my.interserver.net/apiv2/vps',
            ['X-API-KEY' => self::KEY, 'Accept' => 'application/json', 'Authorization' => 'Basic dXNlcjpwYXNz'],
            null,
            403,
            '<html>Attention Required! echo ' . self::KEY . '</html>',
            '',
            ["HTTP/2 403\r\n", "cf-ray: 8c1f2a3b4d5e6f70-LOS\r\n", "set-cookie: __cf_bm=abcdefghijkl\r\n", "\r\n"],
            ['primary_ip' => '104.20.17.90', 'local_ip' => '10.0.0.5', 'total_time' => 0.4211]
        );
        $entries = HttpExchangeLog::stop([self::KEY]);

        $this->assertCount(1, $entries);
        $e = $entries[0];
        $json = json_encode($e);
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $json);
        $this->assertStringNotContainsString('abcdefghijkl', $json);
        $this->assertSame('GET', $e['method']);
        $this->assertSame('https://[hidden]@my.interserver.net/apiv2/vps', $e['url']);
        $this->assertStringStartsWith('RQ7y…', $e['requestHeaders']['X-API-KEY']);
        $this->assertStringContainsString('[56 characters]', $e['requestHeaders']['X-API-KEY']);
        $this->assertSame('Basic [hidden]', $e['requestHeaders']['Authorization']);
        $this->assertContains('cf-ray: 8c1f2a3b4d5e6f70-LOS', $e['responseHeaders']);
        $this->assertContains('HTTP/2 403', $e['responseHeaders']);
        $this->assertSame(403, $e['status']);
        $this->assertSame('104.20.17.90', $e['connectedTo']);
        $this->assertSame(0.421, $e['seconds']);
        $this->assertStringContainsString('Attention Required! echo [hidden]', $e['body']);
    }

    public function test_a_huge_body_is_cut(): void
    {
        HttpExchangeLog::start();
        HttpExchangeLog::record('GET', 'https://x.test/', [], null, 200, str_repeat('a', 50000), '', [], []);
        $e = HttpExchangeLog::stop()[0];

        $this->assertSame(50000, $e['bodyBytes']);
        $this->assertLessThan(20100, strlen($e['body']));
        $this->assertStringContainsString('[cut: 50000 bytes in total]', $e['body']);
    }
}
