<?php

declare(strict_types=1);

namespace CodeVault\Mail;

interface Mailer
{
    /**
     * @throws \Throwable on delivery failure
     */
    public function send(string $to, string $subject, string $html): void;
}
