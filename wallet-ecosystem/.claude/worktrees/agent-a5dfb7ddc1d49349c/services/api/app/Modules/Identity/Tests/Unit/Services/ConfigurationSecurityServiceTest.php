<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exceptions\ConfigurationConflictException;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

function makeConfigService(array $values = []): ConfigurationSecurityService
{
    $stub = new class($values) implements ConfigurationServiceInterface {
        public function __construct(private array $values) {}

        public function get(string $key, bool $requireValue = false): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function getOrDefault(string $key, mixed $default): mixed
        {
            return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
        }

        public function isConfigured(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }

        public function invalidate(string $key): void {}
    };

    return new ConfigurationSecurityService($stub);
}

describe('ConfigurationSecurityService', function (): void {

    it('returns step-up threshold as integer', function (): void {
        $service = makeConfigService(['auth.step_up_threshold' => '10000']);
        expect($service->getStepUpThreshold())->toBe(10000);
    });

    it('returns step-up threshold currency as string', function (): void {
        $service = makeConfigService(['auth.step_up_threshold_currency' => 'BWP']);
        expect($service->getStepUpThresholdCurrency())->toBe('BWP');
    });

    it('falls back to default step-up threshold when unconfigured', function (): void {
        $service = makeConfigService();
        expect($service->getStepUpThreshold())->toBe(10000);
    });

    it('returns OTP TTL as integer', function (): void {
        $service = makeConfigService(['auth.otp.ttl' => '300']);
        expect($service->getOtpTtl())->toBe(300);
    });

    it('returns PIN length as integer', function (): void {
        $service = makeConfigService(['auth.pin.length' => '6']);
        expect($service->getPinLength())->toBe(6);
    });

    it('returns boolean flags correctly for true values', function (): void {
        $service = makeConfigService([
            'auth.refresh_token.rotation_enabled' => 'true',
            'auth.pin.reject_sequential'           => 'true',
            'auth.lockout.progressive_enabled'     => 'true',
        ]);
        expect($service->isRefreshTokenRotationEnabled())->toBeTrue();
        expect($service->isPinRejectSequential())->toBeTrue();
        expect($service->isLockoutProgressiveEnabled())->toBeTrue();
    });

    it('returns false for auth.mfa.admin.sms_enabled by default', function (): void {
        $service = makeConfigService();
        expect($service->isAdminSmsEnabled())->toBeFalse();
    });

    it('returns true for biometric.enabled by default', function (): void {
        $service = makeConfigService();
        expect($service->isBiometricEnabled())->toBeTrue();
    });

    it('returns HMAC-SHA256 as default IP audit hash algorithm', function (): void {
        $service = makeConfigService();
        expect($service->getIpAuditHashAlgorithm())->toBe('HMAC-SHA256');
    });

    describe('security minimum enforcement', function (): void {

        it('accepts OTP TTL at the minimum boundary (60)', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.otp.ttl', 60))->not->toThrow(Exception::class);
        });

        it('rejects OTP TTL below the minimum (59)', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.otp.ttl', 59))
                ->toThrow(ConfigurationConflictException::class);
        });

        it('rejects PIN length below 6 (AC-024)', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.pin.length', 4))
                ->toThrow(ConfigurationConflictException::class);
        });

        it('accepts PIN length at exactly 6', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.pin.length', 6))->not->toThrow(Exception::class);
        });

        it('rejects lockout max_failed_attempts below 1', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.lockout.max_failed_attempts', 0))
                ->toThrow(ConfigurationConflictException::class);
        });

        it('rejects OTP max_attempts below 1', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.otp.max_attempts', 0))
                ->toThrow(ConfigurationConflictException::class);
        });

        it('allows step-up threshold at 0 (always require step-up)', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.step_up_threshold', 0))->not->toThrow(Exception::class);
        });

        it('passes validation for keys without a defined minimum', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.step_up_threshold_currency', 'USD'))
                ->not->toThrow(Exception::class);
        });

        it('rejects non-integer values for numeric minimum keys', function (): void {
            $service = makeConfigService();
            expect(fn () => $service->validateUpdate('auth.otp.ttl', 'not-a-number'))
                ->toThrow(ConfigurationConflictException::class);
        });

    });

})->group('identity', 'configuration', 'unit');
