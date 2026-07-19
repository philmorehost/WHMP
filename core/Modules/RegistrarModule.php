<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Domain registrar modules (blueprint §4.4 domain engine). One
 * implementation per registrar API (ResellerClub, Namecheap,
 * Connectreseller, etc.).
 */
interface RegistrarModule extends Module
{
    public function register(array $params): array;

    public function transfer(array $params): array;

    public function renew(array $params): array;

    /** @return array{success: bool, nameservers: array<int, string>} */
    public function getNameservers(array $params): array;

    public function saveNameservers(array $params): array;

    public function getContactInfo(array $params): array;

    public function saveContactInfo(array $params): array;

    public function getRegistrarLock(array $params): array;

    public function setRegistrarLock(array $params): array;

    public function getEppCode(array $params): array;

    public function enableIdProtection(array $params): array;

    public function disableIdProtection(array $params): array;

    /** @return array{success: bool, expiryDate: string, status: string} */
    public function checkAvailability(array $params): array;

    public function sync(array $params): array;
}
