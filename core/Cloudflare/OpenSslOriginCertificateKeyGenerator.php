<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use Throwable;

/** RSA-2048 SHA-256 key/CSR for a Cloudflare Origin CA certificate. */
final class OpenSslOriginCertificateKeyGenerator implements OriginCertificateKeyGenerator
{
    public function generate(string $commonName): array
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_csr_new')) {
            return ['success'=>false,'message'=>'OpenSSL is not enabled on this server.'];
        }
        try {
            $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
            if ($key===false) { return ['success'=>false,'message'=>'Could not generate a private key.']; }
            $csr=openssl_csr_new(['commonName'=>$commonName],$key,['digest_alg'=>'sha256']);
            if ($csr===false || !openssl_csr_export($csr,$csrPem) || !openssl_pkey_export($key,$keyPem)) {
                return ['success'=>false,'message'=>'Could not create a certificate signing request.'];
            }
            return ['success'=>true,'csr'=>$csrPem,'privateKey'=>$keyPem];
        } catch (Throwable) {
            return ['success'=>false,'message'=>'Could not create a certificate signing request.'];
        }
    }
}
