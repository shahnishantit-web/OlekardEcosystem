<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T034/T035 — Verify SensitiveDataProcessor strips PII from log context.
 * SC-003: Application logs must not contain plaintext sensitive values.
 */
final class LogMaskingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function log_context_masks_pin_and_account_number(): void
    {
        $capturedContext = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedContext): void {
            $capturedContext = $event->context;
        });

        Log::info('test payment event', [
            'pin'            => '123456',
            'account_number' => 'ACC1234567890',
            'amount'         => 500,
        ]);

        $this->assertNotEmpty($capturedContext, 'No MessageLogged event was captured');
        $this->assertNotEquals('123456', $capturedContext['pin'] ?? null, 'pin must not appear in plain form in logs');
        $this->assertNotEquals('ACC1234567890', $capturedContext['account_number'] ?? null, 'account_number must not appear in plain form in logs');
        // Non-sensitive field should be intact
        $this->assertSame(500, $capturedContext['amount'] ?? null);
    }

    /** @test */
    public function log_context_masks_raw_secret_and_document_number(): void
    {
        $capturedContext = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedContext): void {
            $capturedContext = $event->context;
        });

        Log::info('integration event', [
            'raw_secret'      => 'super-secret-value',
            'document_number' => 'PASSPORT-987654',
            'event_type'      => 'credential.created',
        ]);

        $this->assertNotEquals('super-secret-value', $capturedContext['raw_secret'] ?? null);
        $this->assertNotEquals('PASSPORT-987654', $capturedContext['document_number'] ?? null);
        $this->assertSame('credential.created', $capturedContext['event_type'] ?? null);
    }

    /** @test */
    public function log_context_masks_date_of_birth(): void
    {
        $capturedContext = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedContext): void {
            $capturedContext = $event->context;
        });

        Log::info('kyc event', [
            'date_of_birth' => '1990-06-15',
            'kyc_case_id'   => 'case-001',
        ]);

        $this->assertNotEquals('1990-06-15', $capturedContext['date_of_birth'] ?? null);
        $this->assertSame('case-001', $capturedContext['kyc_case_id'] ?? null);
    }

    /** @test */
    public function log_context_masks_password_and_token(): void
    {
        $capturedContext = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$capturedContext): void {
            $capturedContext = $event->context;
        });

        Log::warning('auth attempt', [
            'password'   => 'MySecret123',
            'token'      => 'eyJhbGciOiJIUzI1NiJ9',
            'user_id'    => 'usr-abc',
            'ip_address' => '192.168.1.1',
        ]);

        $this->assertNotEquals('MySecret123', $capturedContext['password'] ?? null);
        $this->assertNotEquals('eyJhbGciOiJIUzI1NiJ9', $capturedContext['token'] ?? null);
        // Non-sensitive fields preserved
        $this->assertSame('usr-abc', $capturedContext['user_id'] ?? null);
        $this->assertSame('192.168.1.1', $capturedContext['ip_address'] ?? null);
    }
}
