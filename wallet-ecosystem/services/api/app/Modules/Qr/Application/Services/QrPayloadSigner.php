<?php

declare(strict_types=1);

namespace App\Modules\Qr\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Qr\Domain\Exceptions\InvalidQrPayloadException;

final class QrPayloadSigner
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function sign(array $fields): string
    {
        $signable = $this->buildSignableFields($fields);
        $json     = json_encode($signable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $hmac  = hash_hmac('sha256', $json, $this->signingKey(), true);
        $sig   = $this->base64UrlEncode($hmac);

        $payload          = $signable;
        $payload['sig']   = $sig;
        if (isset($fields['label'])) {
            $payload['label'] = $fields['label'];
        }

        return $this->base64UrlEncode(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, mixed> Decoded payload fields (excluding `sig`).
     * @throws InvalidQrPayloadException
     */
    public function verify(string $encodedPayload): array
    {
        $json = $this->base64UrlDecode($encodedPayload);

        if ($json === false) {
            throw new InvalidQrPayloadException('Payload is not valid Base64URL.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidQrPayloadException('Payload JSON is malformed.');
        }

        /** @var array<string, mixed> $decoded */

        $receivedSig = $decoded['sig'] ?? null;

        if (! is_string($receivedSig)) {
            throw new InvalidQrPayloadException('Payload signature is missing.');
        }

        unset($decoded['sig'], $decoded['label']);

        $signable    = $this->buildSignableFields($decoded);
        $canonical   = json_encode($signable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedRaw = hash_hmac('sha256', $canonical, $this->signingKey(), true);
        $expectedSig = $this->base64UrlEncode($expectedRaw);

        if (! hash_equals($expectedSig, $receivedSig)) {
            throw new InvalidQrPayloadException('Payload signature does not match.');
        }

        unset($decoded['sig']);

        return $decoded;
    }

    private function buildSignableFields(array $fields): array
    {
        unset($fields['sig'], $fields['label']);

        $filtered = array_filter($fields, fn ($v) => $v !== null);
        ksort($filtered);

        return $filtered;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');

        return base64_decode($padded, true);
    }

    private function signingKey(): string
    {
        return (string) ($this->config->get('qr.signing_key') ?? '');
    }
}
