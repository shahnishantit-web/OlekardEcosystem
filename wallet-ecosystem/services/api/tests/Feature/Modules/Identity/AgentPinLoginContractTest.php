<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a client-generated hash instead of treating it as a PIN', function (): void {
    $response = $this->postJson('/v1/agent/auth/login', [
        'phone' => '+26771000001',
        'pin' => password_hash('123456', PASSWORD_BCRYPT),
    ]);

    $response->assertUnprocessable();
});

it('passes numeric PIN input to Identity verification', function (): void {
    $response = $this->postJson('/v1/agent/auth/login', [
        'phone' => '+26771000001',
        'pin' => '123456',
    ]);

    // The fixture account does not exist in this test. 401 proves the value
    // passed request validation and reached the authoritative Identity lookup.
    $response->assertUnauthorized();
});
