<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Support\ImapMailboxClient;
use PHPUnit\Framework\TestCase;

/**
 * The mailbox connection string is built without touching the (optional)
 * imap extension, so the flag logic — most importantly that
 * `/novalidate-cert` is applied by default to avoid the "[CLOSED] IMAP
 * connection broken (authenticate)" failure on shared hosting — is unit
 * testable.
 */
final class ImapMailboxClientTest extends TestCase
{
    /** @param array{host: string, port: int, encryption: string, username: string, password: string, validate_cert?: bool} $config */
    private function config(string $encryption = 'ssl', bool $validateCert = false): array
    {
        return [
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => $encryption,
            'username' => 'support@example.com',
            'password' => 'secret',
            'validate_cert' => $validateCert,
        ];
    }

    public function test_ssl_default_skips_certificate_validation(): void
    {
        $this->assertSame(
            '{imap.example.com:993/imap/ssl/novalidate-cert/norsh}INBOX',
            ImapMailboxClient::mailboxString($this->config('ssl'))
        );
    }

    public function test_ssl_with_cert_validation_enabled_omits_novalidate_cert(): void
    {
        $this->assertSame(
            '{imap.example.com:993/imap/ssl/norsh}INBOX',
            ImapMailboxClient::mailboxString($this->config('ssl', true))
        );
    }

    public function test_tls_and_none_encryption_flags(): void
    {
        $this->assertSame(
            '{imap.example.com:143/imap/tls/novalidate-cert/norsh}INBOX',
            ImapMailboxClient::mailboxString(array_merge($this->config('tls', false), ['port' => 143]))
        );
        $this->assertSame(
            '{imap.example.com:143/imap/notls/novalidate-cert/norsh}INBOX',
            ImapMailboxClient::mailboxString(array_merge($this->config('none', false), ['port' => 143]))
        );
    }
}
