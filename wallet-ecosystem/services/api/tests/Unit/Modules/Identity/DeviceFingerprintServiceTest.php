<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Domain\Exceptions\InsufficientFingerprintEntropyException;
use App\Modules\Identity\Domain\Services\DeviceFingerprintService;
use Tests\Support\UnitTestCase;

final class DeviceFingerprintServiceTest extends UnitTestCase
{
    private DeviceFingerprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceFingerprintService();
    }

    public function test_compute_hash_returns_64_char_hex_string(): void
    {
        $hash = $this->service->computeHash([
            'device_model'  => 'iPhone14,3',
            'os_version'    => '17.0',
            'screen_width'  => '390',
            'screen_height' => '844',
            'timezone'      => 'Asia/Dubai',
        ], 'ios');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_same_attributes_produce_same_hash(): void
    {
        $attrs = ['device_model' => 'Pixel7', 'os_version' => '14.0', 'timezone' => 'UTC'];

        $hash1 = $this->service->computeHash($attrs, 'android');
        $hash2 = $this->service->computeHash($attrs, 'android');

        $this->assertSame($hash1, $hash2);
    }

    public function test_different_platforms_produce_different_hashes(): void
    {
        $attrs = ['device_model' => 'Generic', 'os_version' => '1.0'];

        $this->assertNotSame(
            $this->service->computeHash($attrs, 'ios'),
            $this->service->computeHash($attrs, 'android'),
        );
    }

    public function test_validate_entropy_throws_for_empty_attributes(): void
    {
        $this->expectException(InsufficientFingerprintEntropyException::class);
        $this->service->validateEntropy([], 'ios', 1);
    }

    public function test_validate_entropy_passes_for_sufficient_attributes(): void
    {
        $this->service->validateEntropy([
            'device_model'  => 'iPhone14,3',
            'os_version'    => '17.0',
            'screen_width'  => '390',
            'screen_height' => '844',
            'timezone'      => 'Asia/Dubai',
        ], 'ios', 10);

        $this->expectNotToPerformAssertions();
    }

    public function test_raw_attributes_are_not_stored_in_hash(): void
    {
        $attrs = ['device_model' => 'SECRET_MODEL', 'os_version' => '99'];
        $hash  = $this->service->computeHash($attrs, 'ios');

        $this->assertStringNotContainsString('SECRET_MODEL', $hash);
    }
}
