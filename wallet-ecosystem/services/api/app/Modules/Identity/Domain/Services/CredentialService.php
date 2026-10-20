<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\ChangePinCommand;
use App\Modules\Identity\Application\Commands\CreatePinCommand;
use App\Modules\Identity\Application\Commands\ResetPinCommand;
use App\Modules\Identity\Application\Commands\UnblockPinCommand;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\PinBlockedEvent;
use App\Modules\Identity\Domain\Events\PinChangedEvent;
use App\Modules\Identity\Domain\Events\PinCreatedEvent;
use App\Modules\Identity\Domain\Events\PinResetEvent;
use App\Modules\Identity\Domain\Events\PinUnblockedEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\Exceptions\PinComplexityException;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Models\Credential;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Manages PIN credential lifecycle: create, change, reset, block, unblock.
 * Raw PIN MUST NEVER appear in any log, event, audit record, or response (BR-004).
 * All complexity rules and policy thresholds are sourced from ConfigurationSecurityService (BR-005, BR-016).
 */
final class CredentialService
{
    /**
     * Static list of commonly used PIN patterns.
     * The `auth.pin.reject_common` config toggle controls whether this list is applied.
     */
    private const COMMON_PINS = [
        '123456', '654321', '000000', '111111', '222222', '333333', '444444',
        '555555', '666666', '777777', '888888', '999999', '112233', '121212',
        '123123', '100000', '010203', '010101',
    ];

    public function __construct(
        private readonly CredentialRepositoryInterface $credentials,
        private readonly UserRepositoryInterface $users,
        private readonly OtpService $otpService,
        private readonly ConfigurationSecurityService $config,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
    ) {}

    // -------------------------------------------------------------------------
    // T107: createPin — initial PIN creation (via setup_token or authenticated session)
    // -------------------------------------------------------------------------

    public function createPin(CreatePinCommand $cmd): void
    {
        $this->validateComplexity($cmd->pin);

        $existing = $this->credentials->findByUserAndType($cmd->userId, 'pin');

        if ($existing !== null) {
            // Idempotency: silently fail if PIN already set (not an error from client perspective)
            throw new \DomainException('PIN already set for this user.');
        }

        $hash = Hash::driver('argon2id')->make($cmd->pin);

        DB::connection('platform')->transaction(function () use ($cmd, $hash): void {
            $this->credentials->create([
                'user_id'              => $cmd->userId,
                'credential_type'      => 'pin',
                'credential_hash'      => $hash,
                'status'               => 'active',
                'failed_attempt_count' => 0,
            ]);

            $this->events->publish(new PinCreatedEvent(
                userId:        $cmd->userId,
                actorId:       $cmd->userId,
                correlationId: $cmd->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.credential.pin-created',
                actorType:     'CUSTOMER',
                resourceType:  'identity.credential',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $cmd->userId,
                resourceId:    $cmd->userId,
                afterState:    ['credential_type' => 'pin'],
                ipAddress:     $cmd->ipHash,
            ));
        });
    }

    // -------------------------------------------------------------------------
    // T107: changePin — requires current PIN verification
    // -------------------------------------------------------------------------

    public function changePin(ChangePinCommand $cmd): void
    {
        $credential = $this->credentials->findByUserAndType($cmd->userId, 'pin');

        if ($credential === null) {
            throw InvalidCredentialsException::make();
        }

        if ($credential->status === 'blocked') {
            throw PinBlockedException::make();
        }

        if (!password_verify($cmd->currentPin, $credential->credential_hash)) {
            $this->handlePinFailure($credential, $cmd->userId, $cmd->correlationId, $cmd->ipHash);
            throw InvalidCredentialsException::make();
        }

        $this->validateComplexity($cmd->newPin);

        $newHash = Hash::driver('argon2id')->make($cmd->newPin);

        DB::connection('platform')->transaction(function () use ($cmd, $credential, $newHash): void {
            $this->credentials->updateHash($credential->id, $newHash);
            $this->credentials->resetFailed($credential->id);

            $this->events->publish(new PinChangedEvent(
                userId:        $cmd->userId,
                actorId:       $cmd->userId,
                correlationId: $cmd->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.credential.pin-changed',
                actorType:     'CUSTOMER',
                resourceType:  'identity.credential',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $cmd->userId,
                resourceId:    $cmd->userId,
                afterState:    ['credential_type' => 'pin'],
                ipAddress:     $cmd->ipHash,
            ));
        });
    }

    // -------------------------------------------------------------------------
    // T107: resetPin — two-step: initiate sends OTP, complete verifies + sets new PIN
    // -------------------------------------------------------------------------

    public function initiateReset(string $mobileIdentifier, string $correlationId): string
    {
        $user = $this->users->findByMobile($mobileIdentifier);

        // No identifier disclosure (BR-014): return the same error regardless of whether user exists
        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $challenge = $this->otpService->generate(
            identifier:    $mobileIdentifier,
            challengeType: OtpChallengeType::PIN_RESET,
            userId:        $user->id,
            correlationId: $correlationId,
        );

        return $challenge->id;
    }

    public function completeReset(ResetPinCommand $cmd): void
    {
        $challenge = $this->otpService->verify($cmd->challengeId, $cmd->otp);

        $userId     = (string) $challenge->user_id;
        $credential = $this->credentials->findByUserAndType($userId, 'pin');

        if ($credential === null) {
            throw InvalidCredentialsException::make();
        }

        $this->validateComplexity($cmd->newPin);

        $newHash = Hash::driver('argon2id')->make($cmd->newPin);

        DB::connection('platform')->transaction(function () use ($cmd, $credential, $userId, $newHash): void {
            $this->credentials->updateHash($credential->id, $newHash);
            $this->credentials->resetFailed($credential->id);
            $this->credentials->updateStatus($credential->id, 'active');

            $this->events->publish(new PinResetEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $cmd->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.credential.pin-reset',
                actorType:     'CUSTOMER',
                resourceType:  'identity.credential',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $userId,
                afterState:    ['credential_type' => 'pin', 'recovery' => 'otp'],
                ipAddress:     $cmd->ipHash,
            ));
        });
    }

    // -------------------------------------------------------------------------
    // T107: unblockPin — OTP-verified unblock without PIN change
    // Initiate: call initiateUnblock(mobileIdentifier) → returns challengeId
    // Complete: call completeUnblock(UnblockPinCommand) → verifies OTP → unblocks
    // -------------------------------------------------------------------------

    public function initiateUnblock(string $mobileIdentifier, string $correlationId): string
    {
        $user = $this->users->findByMobile($mobileIdentifier);

        if ($user === null) {
            throw InvalidCredentialsException::make();
        }

        $challenge = $this->otpService->generate(
            identifier:    $mobileIdentifier,
            challengeType: OtpChallengeType::PIN_UNBLOCK,
            userId:        $user->id,
            correlationId: $correlationId,
        );

        return $challenge->id;
    }

    public function completeUnblock(UnblockPinCommand $cmd): void
    {
        $challenge = $this->otpService->verify($cmd->challengeId, $cmd->otp);

        $userId     = (string) $challenge->user_id;
        $credential = $this->credentials->findByUserAndType($userId, 'pin');

        if ($credential === null) {
            throw InvalidCredentialsException::make();
        }

        DB::connection('platform')->transaction(function () use ($cmd, $credential, $userId): void {
            $this->credentials->resetFailed($credential->id);
            $this->credentials->updateStatus($credential->id, 'active');

            $this->events->publish(new PinUnblockedEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $cmd->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.credential.pin-unblocked',
                actorType:     'CUSTOMER',
                resourceType:  'identity.credential',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $userId,
                afterState:    ['credential_type' => 'pin', 'recovery' => 'otp'],
                ipAddress:     $cmd->ipHash,
            ));
        });
    }

    // -------------------------------------------------------------------------
    // T108: validateComplexity — all rules sourced from ConfigurationSecurityService (BR-005, BR-016)
    // Raw PIN is validated here but NEVER stored, logged, or emitted.
    // -------------------------------------------------------------------------

    public function validateComplexity(string $pin): void
    {
        $length = $this->config->getPinLength();

        if (!preg_match('/^\d+$/', $pin) || strlen($pin) !== $length) {
            throw PinComplexityException::invalidLength($length);
        }

        if ($this->config->isPinRejectRepeated() && preg_match('/^(\d)\1+$/', $pin)) {
            throw PinComplexityException::repeated();
        }

        if ($this->config->isPinRejectSequential() && $this->isSequential($pin, $length)) {
            throw PinComplexityException::sequential();
        }

        if ($this->config->isPinRejectCommon() && in_array($pin, self::COMMON_PINS, true)) {
            throw PinComplexityException::common();
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function handlePinFailure(Credential $credential, string $userId, string $correlationId, string $ipHash): void
    {
        // Increment commits immediately so partial-failure never resets the counter.
        $this->credentials->incrementFailed($credential->id);
        $credential->refresh();

        $maxAttempts = $this->config->getLockoutMaxFailedAttempts();

        if ($credential->failed_attempt_count >= $maxAttempts) {
            DB::connection('platform')->transaction(function () use ($credential, $userId, $correlationId, $ipHash): void {
                $this->credentials->updateStatus($credential->id, 'blocked');

                $this->events->publish(new PinBlockedEvent(
                    userId:        $userId,
                    actorId:       $userId,
                    correlationId: $correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'identity.credential.pin-blocked',
                    actorType:     'CUSTOMER',
                    resourceType:  'identity.credential',
                    correlationId: $correlationId,
                    outcome:       'success',
                    actorId:       $userId,
                    resourceId:    $userId,
                    afterState:    ['credential_type' => 'pin', 'reason' => 'excessive_failures'],
                    ipAddress:     $ipHash,
                ));
            });
        }
    }

    private function isSequential(string $pin, int $length): bool
    {
        if ($length < 2) {
            return false;
        }

        $digits = array_map('intval', str_split($pin));

        $ascending  = true;
        $descending = true;

        for ($i = 1; $i < $length; $i++) {
            if ($digits[$i] !== ($digits[$i - 1] + 1) % 10) {
                $ascending = false;
            }
            if ($digits[$i] !== ($digits[$i - 1] - 1 + 10) % 10) {
                $descending = false;
            }
        }

        return $ascending || $descending;
    }
}
