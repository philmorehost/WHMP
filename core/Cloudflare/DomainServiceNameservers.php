<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Domains\DomainService;

/** NameserverGateway over DomainService — the same path the client's own nameserver page uses. */
final class DomainServiceNameservers implements NameserverGateway
{
    public function __construct(private readonly DomainService $domains)
    {
    }

    public function get(int $domainId): array
    {
        return $this->domains->getNameservers($domainId);
    }

    public function save(int $domainId, array $nameservers): array
    {
        return $this->domains->saveNameservers($domainId, $nameservers);
    }

    public function supportsDs(int $domainId): bool
    {
        return $this->domains->supportsDsRecords($domainId);
    }

    public function changeDs(int $domainId, array $ds, bool $add): array
    {
        return $this->domains->changeDsRecord($domainId, $ds, $add);
    }
}
