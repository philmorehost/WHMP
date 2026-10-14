<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

/** Creates an Origin CA CSR and a matching private key (which is never persisted). */
interface OriginCertificateKeyGenerator
{
    /** @return array{success:bool,csr?:string,privateKey?:string,message?:string} */
    public function generate(string $commonName): array;
}
