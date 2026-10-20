<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\CompleteStepUpCommand;
use App\Modules\Identity\Application\DTOs\StepUpTokenDTO;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Step-Up Authentication orchestration.
 *
 * Dual-trigger: amount > configured threshold OR explicit risk signal (FR-E01-046; BR-023).
 * Threshold MUST be read from ConfigurationSecurityService at runtime — never hard-coded (AC-013, AC-017).
 * Cross-currency: uses platform FX mechanism; FX unavailable → fail-safe: require step-up (BR-024).
 */
final class StepUpService
{

    public function __construct(
        private readonly ConfigurationSecurityService  $config,
        private readonly CredentialRepositoryInterface $credentials,
        private readonly MfaService                    $mfa,
        private readonly TokenServiceContract          $tokenService,
    ) {}

    // -------------------------------------------------------------------------
    // Threshold evaluation (T124; FR-E01-037; FR-E01-046; BR-023; BR-024)
    // -------------------------------------------------------------------------

    /**
     * Evaluate whether step-up is required for a given transaction amount and currency.
     * Reads the configured threshold at runtime — threshold MUST NOT be hard-coded (AC-013; AC-017).
     *
     * Same currency → direct comparison.
     * Different currency → platform FX conversion; FX unavailable → true (fail-safe, BR-024).
     */
    public function evaluateAmount(float $amount, string $currency): bool
    {
        $threshold         = $this->config->getStepUpThreshold();
        $thresholdCurrency = $this->config->getStepUpThresholdCurrency();

        if (strtoupper($currency) === strtoupper($thresholdCurrency)) {
            return $amount > $threshold;
        }

        $converted = $this->convertCurrency($amount, $currency, $thresholdCurrency);

        if ($converted === null) {
            // FX unavailable → fail-safe: require step-up (BR-024)
            return true;
        }

        return $converted > $threshold;
    }

    /**
     * Evaluate whether step-up is required based on an explicit risk-engine signal.
     * Independent of the amount threshold (FR-E01-046; BR-023).
     */
    public function evaluateRiskSignal(bool $riskRequired): bool
    {
        return $riskRequired;
    }

    // -------------------------------------------------------------------------
    // Challenge issuance (T124; T126; FR-E01-037)
    // -------------------------------------------------------------------------

    /**
     * Issue an opaque step-up challenge token and store payload in Redis.
     *
     * @return array{step_up_token: string, methods: string[], expires_at: string}
     */
    public function initiateChallenge(
        string $userId,
        string $sessionId,
        string $actorType,
        string $scope,
        string $correlationId,
    ): array {
        $challengeTtl = $this->config->getStepUpTokenTtl();
        $rawToken     = base64_encode(random_bytes(32));
        $tokenHash    = hash('sha256', $rawToken);
        $expiresAt    = now()->addSeconds($challengeTtl);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            IdentityRedisKeys::stepUpPending($tokenHash),
            $challengeTtl,
            json_encode([
                'user_id'        => $userId,
                'session_id'     => $sessionId,
                'actor_type'     => $actorType,
                'scope'          => $scope,
                'correlation_id' => $correlationId,
            ], JSON_THROW_ON_ERROR),
        );

        return [
            'step_up_token' => $rawToken,
            'methods'       => $this->resolveAvailableMethods($userId),
            'expires_at'    => $expiresAt->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------
    // Challenge verification (T124; T126; FR-E01-037; FR-E01-038)
    // -------------------------------------------------------------------------

    /**
     * Verify a step-up challenge by confirming session binding and credential.
     * Single-use: challenge is deleted from Redis on success.
     * Issues a purpose-scoped step-up JWT on success (aud = "step-up:{scope}").
     *
     * @throws InvalidCredentialsException on invalid/expired token, binding mismatch, or wrong credential
     */
    public function verifyChallenge(CompleteStepUpCommand $cmd): StepUpTokenDTO
    {
        $tokenHash = hash('sha256', $cmd->stepUpToken);
        $redis     = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $raw       = $redis->get(IdentityRedisKeys::stepUpPending($tokenHash));

        if ($raw === null || $raw === false) {
            throw InvalidCredentialsException::make();
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Session and user binding (replay/cross-session protection)
        if (($payload['user_id'] ?? '')    !== $cmd->userId
            || ($payload['session_id'] ?? '') !== $cmd->sessionId) {
            throw InvalidCredentialsException::make();
        }

        $scope = (string) ($payload['scope'] ?? '');

        $valid = match ($cmd->method) {
            'totp'  => $this->verifyTotp($cmd->userId, $cmd->credential),
            'pin'   => $this->verifyPin($cmd->userId, $cmd->credential),
            default => false,
        };

        if (!$valid) {
            throw InvalidCredentialsException::make();
        }

        // Consume the challenge token (single-use)
        $redis->del(IdentityRedisKeys::stepUpPending($tokenHash));

        return $this->tokenService->issueStepUpToken(
            userId:        $cmd->userId,
            sessionId:     $cmd->sessionId,
            actorType:     $cmd->actorType,
            scope:         $scope,
            ipHash:        $cmd->ipHash,
            correlationId: $cmd->correlationId,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return string[] enrolled MFA methods + 'pin' if active PIN credential exists */
    private function resolveAvailableMethods(string $userId): array
    {
        $methods = $this->mfa->getMfaMethods($userId);

        $pinCred = $this->credentials->findByUserAndType($userId, 'pin');
        if ($pinCred !== null && $pinCred->status === 'active') {
            $methods[] = 'pin';
        }

        return array_values(array_unique($methods));
    }

    private function verifyTotp(string $userId, string $code): bool
    {
        $cred = $this->credentials->findByUserAndType($userId, 'mfa_totp');
        if ($cred === null || $cred->status !== 'active') {
            return false;
        }
        $auth = new Authenticator(new AuthenticatorOptions);
        $auth->setSecret((string) $cred->credential_hash);
        return $auth->verify($code);
    }

    private function verifyPin(string $userId, string $pin): bool
    {
        $cred = $this->credentials->findByUserAndType($userId, 'pin');
        if ($cred === null || $cred->status !== 'active') {
            return false;
        }
        return Hash::check($pin, (string) $cred->credential_hash);
    }

    /**
     * Convert amount from $fromCurrency to $toCurrency via platform FX mechanism.
     * Returns null when FX is unavailable — caller MUST treat null as "step-up required" (BR-024).
     * Platform FX (E09) will be wired here when available; stub returns null for cross-currency paths.
     */
    private function convertCurrency(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        // Platform FX service (E09) is not yet available.
        // BR-024: FX unavailable → fail-safe (return null; caller requires step-up).
        return null;
    }
}
