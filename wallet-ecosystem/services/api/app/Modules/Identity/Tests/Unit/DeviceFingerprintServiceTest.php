<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Exceptions\InsufficientFingerprintEntropyException;
use App\Modules\Identity\Domain\Services\DeviceFingerprintService;

$service = new DeviceFingerprintService();

describe('DeviceFingerprintService::computeHash', function () use ($service): void {

    it('produces a 64-character hex string', function () use ($service): void {
        $hash = $service->computeHash([
            'os_version'     => '14',
            'hardware_model' => 'Pixel 9',
            'android_id'     => 'a1b2c3d4e5f67890',
        ], 'android');

        expect(strlen($hash))->toBe(64);
        expect(ctype_xdigit($hash))->toBeTrue();
    });

    it('is deterministic: same attributes produce the same hash', function () use ($service): void {
        $attrs = ['os_version' => '14', 'hardware_model' => 'Pixel 9', 'android_id' => 'abc123'];

        $hash1 = $service->computeHash($attrs, 'android');
        $hash2 = $service->computeHash($attrs, 'android');

        expect($hash1)->toBe($hash2);
    });

    it('is order-independent: attributes sorted before hashing', function () use ($service): void {
        $attrs1 = ['android_id' => 'abc', 'os_version' => '14', 'hardware_model' => 'X'];
        $attrs2 = ['hardware_model' => 'X', 'os_version' => '14', 'android_id' => 'abc'];

        expect($service->computeHash($attrs1, 'android'))->toBe($service->computeHash($attrs2, 'android'));
    });

    it('strips null values before hashing (normalized)', function () use ($service): void {
        $withNull    = ['os_version' => '14', 'optional' => null, 'android_id' => 'abc'];
        $withoutNull = ['os_version' => '14', 'android_id' => 'abc'];

        expect($service->computeHash($withNull, 'android'))->toBe($service->computeHash($withoutNull, 'android'));
    });

    it('produces different hashes for different inputs', function () use ($service): void {
        $hash1 = $service->computeHash(['android_id' => 'aaa'], 'android');
        $hash2 = $service->computeHash(['android_id' => 'bbb'], 'android');

        expect($hash1)->not->toBe($hash2);
    });

})->group('identity', 'device', 'unit');

describe('DeviceFingerprintService::validateEntropy', function () use ($service): void {

    it('does not throw when entropy meets the minimum', function () use ($service): void {
        $attrs = [
            'os_version'     => '14.0',
            'hardware_model' => 'Pixel9ProXLMax',
            'android_id'     => 'a1b2c3d4e5f67890abcdef01234567890',
            'locale'         => 'en-US-x-lvariant-POSIX',
        ];

        expect(fn () => $service->validateEntropy($attrs, 'android', 32))->not->toThrow(InsufficientFingerprintEntropyException::class);
    });

    it('throws InsufficientFingerprintEntropyException when below minimum bits', function () use ($service): void {
        $lowEntropyAttrs = ['android_id' => 'aaaa'];

        expect(fn () => $service->validateEntropy($lowEntropyAttrs, 'android', 200))
            ->toThrow(InsufficientFingerprintEntropyException::class);
    });

    it('throws when attributes are empty', function () use ($service): void {
        expect(fn () => $service->validateEntropy([], 'android', 1))
            ->toThrow(InsufficientFingerprintEntropyException::class);
    });

})->group('identity', 'device', 'unit');

describe('DeviceFingerprintService::detectEmulatorIndicators', function () use ($service): void {

    it('returns true when android_id is all-zero (emulator sentinel)', function () use ($service): void {
        $attrs = ['android_id' => '0000000000000000', 'hardware_model' => 'Pixel 9', 'os_version' => '14'];

        expect($service->detectEmulatorIndicators($attrs, 'android'))->toBeTrue();
    });

    it('returns true when hardware_model contains "sdk" (Android emulator)', function () use ($service): void {
        $attrs = ['android_id' => 'abc123', 'hardware_model' => 'Android SDK built for x86', 'os_version' => '14'];

        expect($service->detectEmulatorIndicators($attrs, 'android'))->toBeTrue();
    });

    it('returns true when hardware_model contains "emulator"', function () use ($service): void {
        $attrs = ['android_id' => 'abc123', 'hardware_model' => 'generic_x86_emulator', 'os_version' => '14'];

        expect($service->detectEmulatorIndicators($attrs, 'android'))->toBeTrue();
    });

    it('returns false for a real device android_id', function () use ($service): void {
        $attrs = ['android_id' => 'a1b2c3d4e5f67890', 'hardware_model' => 'Pixel 9', 'os_version' => '14'];

        expect($service->detectEmulatorIndicators($attrs, 'android'))->toBeFalse();
    });

    it('returns true for iOS simulator hardware_model x86_64', function () use ($service): void {
        $attrs = ['hardware_model' => 'x86_64', 'idfv' => '11111111-1111-1111-1111-111111111111', 'os_version' => '17'];

        expect($service->detectEmulatorIndicators($attrs, 'ios'))->toBeTrue();
    });

    it('returns true for iOS simulator null IDFV', function () use ($service): void {
        $attrs = ['hardware_model' => 'iPhone15,2', 'idfv' => '00000000-0000-0000-0000-000000000000', 'os_version' => '17'];

        expect($service->detectEmulatorIndicators($attrs, 'ios'))->toBeTrue();
    });

    it('returns false for unknown platform', function () use ($service): void {
        expect($service->detectEmulatorIndicators(['android_id' => '0000000000000000'], 'web'))->toBeFalse();
    });

})->group('identity', 'device', 'unit');
