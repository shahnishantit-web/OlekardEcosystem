<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Jwks;

use App\Modules\Identity\Domain\Contracts\JwkPublicKey;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\JwkSigningKey;
use RuntimeException;

/**
 * Reads the active ES256 private key from IDENTITY_JWT_PRIVATE_KEY env secret.
 * During rotation, the old public key is also served if IDENTITY_JWT_OLD_PUBLIC_KEY is set.
 *
 * kid = first 12 hex characters of SHA-256(DER-encoded public key) — stable across restarts.
 *
 * Private key material MUST NOT be logged, serialized to any response, or placed in config tables.
 */
final class LocalJwksProvider implements JwksProviderContract
{
    public function getSigningKey(): JwkSigningKey
    {
        $pem = env('IDENTITY_JWT_PRIVATE_KEY');
        if (empty($pem)) {
            throw new RuntimeException('IDENTITY_JWT_PRIVATE_KEY is not set. Cannot issue JWTs.');
        }

        $pem = $this->normalizePem($pem);
        $kid = $this->deriveKid($pem);

        return new JwkSigningKey(
            kid:           $kid,
            privateKeyPem: $pem,
            algorithm:     'ES256',
        );
    }

    public function getVerificationKeys(): array
    {
        $keys = [];

        $activePem = env('IDENTITY_JWT_PRIVATE_KEY');
        if (!empty($activePem)) {
            $activePem = $this->normalizePem($activePem);
            $keys[]    = $this->buildPublicKey($activePem, $this->deriveKid($activePem));
        }

        // During key rotation: old public key is served until all existing tokens expire
        $oldPem = env('IDENTITY_JWT_OLD_PUBLIC_KEY');
        if (!empty($oldPem)) {
            $oldPem = $this->normalizePem($oldPem);
            $keys[] = $this->buildPublicKey($oldPem, $this->deriveKid($oldPem));
        }

        return $keys;
    }

    public function getCurrentKid(): string
    {
        $pem = env('IDENTITY_JWT_PRIVATE_KEY');
        if (empty($pem)) {
            throw new RuntimeException('IDENTITY_JWT_PRIVATE_KEY is not set.');
        }

        return $this->deriveKid($this->normalizePem($pem));
    }

    private function normalizePem(string $pem): string
    {
        // Allow newlines encoded as literal \n in env vars (common in Docker/CI secrets)
        return str_replace('\n', "\n", $pem);
    }

    private function deriveKid(string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            // Accept both private and public PEM (for IDENTITY_JWT_OLD_PUBLIC_KEY)
            $key = openssl_pkey_get_public($privateKeyPem);
        }
        if ($key === false) {
            throw new RuntimeException('Could not parse key PEM for kid derivation.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Could not extract key details for kid derivation.');
        }

        // DER-encode the public key and hash it; kid = first 12 hex chars
        $publicKeyDer = $this->extractPublicKeyDer($details);

        return substr(hash('sha256', $publicKeyDer), 0, 12);
    }

    private function extractPublicKeyDer(array $details): string
    {
        // Export public key as PEM, then strip header/footer and decode
        $publicPem = $details['key'];
        $stripped   = preg_replace('/-----.*?-----|\s/', '', $publicPem);

        return base64_decode($stripped, strict: true) ?: throw new RuntimeException('Failed to decode public key DER.');
    }

    private function buildPublicKey(string $pem, string $kid): JwkPublicKey
    {
        $key = openssl_pkey_get_private($pem) ?: openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new RuntimeException('Could not load key for JWK construction.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec'])) {
            throw new RuntimeException('Key is not an EC key; only ES256 (P-256) is supported.');
        }

        $ec = $details['ec'];

        return new JwkPublicKey(
            kid: $kid,
            kty: 'EC',
            crv: 'P-256',
            use: 'sig',
            alg: 'ES256',
            x:   rtrim(strtr(base64_encode($ec['x']), '+/', '-_'), '='),
            y:   rtrim(strtr(base64_encode($ec['y']), '+/', '-_'), '='),
        );
    }
}
