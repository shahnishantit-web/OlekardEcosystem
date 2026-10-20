<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TbdConfigurationException;
use App\Modules\Platform\Domain\ValueObjects\Actor;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationMiddleware
{
    private const JWKS_CACHE_PREFIX = 'jwks:';
    private const JWKS_CACHE_TTL    = 3600;

    private const EXCLUDED_PATHS = [
        'health', 'ready', 'startup', 'api/docs',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::EXCLUDED_PATHS as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        $jwksUri = env('IDENTITY_JWKS_URI');
        if (empty($jwksUri)) {
            throw new TbdConfigurationException('IDENTITY_JWKS_URI');
        }

        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            throw new AuthenticationException('AUTHENTICATION_REQUIRED');
        }

        $token = substr($authHeader, 7);

        try {
            $claims = $this->verifyToken($token, $jwksUri);
        } catch (\Throwable $e) {
            Log::warning('authentication.jwt_verification_failed', [
                'correlation_id' => $request->header('X-Correlation-ID', ''),
                'error'          => $e->getMessage(),
            ]);

            throw new AuthenticationException('AUTHENTICATION_REQUIRED');
        }

        $actorType = $claims['actor_type'] ?? 'user';
        $actorId   = $claims['sub'] ?? null;

        if ($actorId === null) {
            throw new AuthenticationException('AUTHENTICATION_REQUIRED');
        }

        $actor = match ($actorType) {
            'service' => Actor::service($actorId),
            'system'  => Actor::system(),
            default   => Actor::user($actorId),
        };

        // Store actor in request for downstream use
        $request->attributes->set('actor', $actor);
        $request->attributes->set('actor_id', $actorId);

        return $next($request);
    }

    /** @return array<string, mixed> */
    private function verifyToken(string $token, string $jwksUri): array
    {
        // Decode header to get kid
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed JWT.');
        }

        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($headerJson === false) {
            throw new \InvalidArgumentException('Cannot decode JWT header.');
        }

        $header = json_decode($headerJson, true);
        $kid    = $header['kid'] ?? 'default';

        // Fetch or cache JWKS public key
        $cacheKey = self::JWKS_CACHE_PREFIX.$kid;
        $publicKey = Cache::store('redis')->remember($cacheKey, self::JWKS_CACHE_TTL, function () use ($jwksUri, $kid) {
            return $this->fetchPublicKey($jwksUri, $kid);
        });

        // Decode and verify claims without exposing JWT payload in logs
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payloadJson === false) {
            throw new \InvalidArgumentException('Cannot decode JWT payload.');
        }

        $claims = json_decode($payloadJson, true);

        $this->validateClaims($claims);

        return $claims;
    }

    private function fetchPublicKey(string $jwksUri, string $kid): string
    {
        $response = file_get_contents($jwksUri.'/.well-known/jwks.json');
        if ($response === false) {
            throw new \RuntimeException('Cannot fetch JWKS from identity provider.');
        }

        $jwks = json_decode($response, true);
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return json_encode($key);
            }
        }

        throw new \RuntimeException("No key with kid={$kid} found in JWKS.");
    }

    /** @param array<string, mixed> $claims */
    private function validateClaims(array $claims): void
    {
        if (($claims['exp'] ?? 0) < time()) {
            throw new \InvalidArgumentException('Token expired.');
        }

        $expectedIss = env('JWT_ISSUER');
        if ($expectedIss && ($claims['iss'] ?? null) !== $expectedIss) {
            throw new \InvalidArgumentException('Token issuer mismatch.');
        }

        $expectedAud = env('JWT_AUDIENCE');
        if ($expectedAud) {
            $aud = $claims['aud'] ?? [];
            if (is_string($aud)) {
                $aud = [$aud];
            }
            if (! in_array($expectedAud, $aud, true)) {
                throw new \InvalidArgumentException('Token audience mismatch.');
            }
        }
    }
}
