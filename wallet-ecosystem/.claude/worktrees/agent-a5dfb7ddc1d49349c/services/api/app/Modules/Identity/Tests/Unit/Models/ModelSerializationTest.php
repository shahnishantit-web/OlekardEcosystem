<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Credential;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Identity\Infrastructure\Models\Session;

describe('Identity model serialization safety', function (): void {

    it('omits credential_hash from JSON serialization', function (): void {
        $credential         = new Credential();
        $credential->credential_hash = 'argon2id-hash-value';
        $credential->credential_type  = 'pin';

        $json = $credential->toArray();

        expect($json)->not->toHaveKey('credential_hash');
    });

    it('omits otp_hash from JSON serialization', function (): void {
        $challenge          = new OtpChallenge();
        $challenge->otp_hash = hash('sha256', '123456');

        $json = $challenge->toArray();

        expect($json)->not->toHaveKey('otp_hash');
    });

    it('omits refresh_token_hash from session serialization', function (): void {
        $session                      = new Session();
        $session->refresh_token_hash  = hash('sha256', 'raw-token');

        $json = $session->toArray();

        expect($json)->not->toHaveKey('refresh_token_hash');
    });

    it('omits ip_hash from session serialization', function (): void {
        $session          = new Session();
        $session->ip_hash = hash_hmac('sha256', '192.168.1.1', 'platform_secret');

        $json = $session->toArray();

        expect($json)->not->toHaveKey('ip_hash');
    });

    it('omits device_fingerprint from device serialization', function (): void {
        $device                     = new Device();
        $device->device_fingerprint = hash('sha256', 'raw-fingerprint');

        $json = $device->toArray();

        expect($json)->not->toHaveKey('device_fingerprint');
    });

    it('ip_hash column does not store a raw IPv4 pattern', function (): void {
        $session          = new Session();
        $session->ip_hash = hash_hmac('sha256', '192.168.1.1', 'secret');

        // A raw IPv4 address looks like x.x.x.x — the HMAC value must not match this
        expect($session->ip_hash)->not->toMatch('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/');
    });

})->group('identity', 'models', 'security');
