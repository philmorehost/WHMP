<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

/**
 * The two registrar calls the Cloudflare add-on needs, for domains registered
 * with us. Production: DomainServiceNameservers (the domain's own registrar
 * module); tests: a fake.
 */
interface NameserverGateway
{
    /** @return array{success: bool, nameservers?: array<int, string>, message?: string} */
    public function get(int $domainId): array;

    /**
     * @param array<int, string> $nameservers
     * @return array{success: bool, message?: string}
     */
    public function save(int $domainId, array $nameservers): array;
}
