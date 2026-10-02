<?php

declare(strict_types=1);

namespace CodeVault\Mail;

interface Mailer
{
    /**
     * Send one message.
     *
     * `$from` overrides the configured sender FOR THIS MESSAGE ONLY, as
     * ['email' => ..., 'name' => ...]. It exists so a reseller's customer can be
     * written to AS the reseller — the store's own address and name, not ours.
     * Null keeps the platform's configured sender, which is what every other email
     * in the system should use.
     *
     * The implementation is responsible for validating the override exactly as it
     * validates the configured address: it is user input reaching the SMTP dialogue
     * and the From: header verbatim.
     *
     * @param array{email?: string, name?: string}|null $from
     * @throws \Throwable on delivery failure
     */
    public function send(string $to, string $subject, string $html, ?array $from = null): void;
}
