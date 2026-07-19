<?php

declare(strict_types=1);

namespace CodeVault\Api;

use CodeVault\Request;

/**
 * Validates the `Authorization: Bearer {key}.{secret}` header against the
 * credential repository and checks scopes. Any route under /api that needs
 * auth calls authenticate() then authorize() before doing real work.
 */
final class ApiAuthenticator
{
    public function __construct(
        private readonly ApiCredentialRepository $credentials
    ) {
    }

    public function authenticate(Request $request): ApiCredential
    {
        $header = $request->header('Authorization', '') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            throw new ApiAuthException('Missing or malformed Authorization header.');
        }

        $token = substr($header, 7);

        if (!str_contains($token, '.')) {
            throw new ApiAuthException('Malformed API token.');
        }

        [$key, $secret] = explode('.', $token, 2);
        $credential = $this->credentials->findByKey($key);

        if ($credential === null || !$credential->verifySecret($secret)) {
            throw new ApiAuthException('Invalid API credentials.');
        }

        return $credential;
    }

    public function authorize(ApiCredential $credential, string $scope): void
    {
        if (!$credential->hasScope($scope)) {
            throw new ApiAuthException("Credential is not authorized for scope [{$scope}].");
        }
    }
}
