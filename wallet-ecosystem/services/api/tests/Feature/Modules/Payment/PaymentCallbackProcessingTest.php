<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\Services\PaymentCallbackService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Infrastructure\Adapters\BankCallbackVerifier;
use App\Modules\Payment\Infrastructure\Adapters\MockBankCallbackParser;
use App\Modules\Payment\Infrastructure\Models\PaymentCallbackModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

/**
 * T137: Feature tests for callback processing pipeline.
 * Tests: raw body persisted, valid/invalid HMAC, duplicate idempotency,
 * out-of-order handling, payment.confirmed outbox event, dead-letter handling.
 *
 * Note: Full pipeline tests require Docker (DB + queue). These tests cover
 * unit-testable aspects of the pipeline.
 */
final class PaymentCallbackProcessingTest extends TestCase
{
    use RefreshDatabase;
    use \Illuminate\Foundation\Testing\CreatesApplication;

    private string $mockSecret = 'test-callback-secret-123';

    // ─── Test 1: Callback controller persists raw body before queue dispatch ─

    public function test_callback_route_accepts_valid_signed_payload_returns_202(): void
    {
        $this->markTestSkipped('T137: Requires Docker for full HTTP route test (DB + queue).');
    }

    // ─── Test 2: Valid HMAC signature accepted ────────────────────────────────

    public function test_valid_hmac_signature_accepted_by_parser(): void
    {
        /** @var MockObject&ConfigurationServiceInterface $config */
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn($this->mockSecret);

        $verifier = new BankCallbackVerifier($config);
        $parser   = new MockBankCallbackParser($verifier);

        $payload   = json_encode([
            'transaction_ref' => (string) Uuid::uuid7(),
            'status'          => 'success',
            'timestamp'       => (string) time(),
        ]);
        $signature = hash_hmac('sha256', $payload, $this->mockSecret);

        $event = $parser->parse($payload, $signature, []);

        self::assertSame('payment.succeeded', $event->normalizedEventType);
    }

    // ─── Test 3: Invalid signature → callback marked signature_invalid ────────

    public function test_invalid_signature_rejected_by_parser(): void
    {
        /** @var MockObject&ConfigurationServiceInterface $config */
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn($this->mockSecret);

        $verifier = new BankCallbackVerifier($config);
        $parser   = new MockBankCallbackParser($verifier);

        $payload = json_encode(['transaction_ref' => 'attempt-1', 'status' => 'success', 'timestamp' => '12345']);

        $this->expectException(WebhookSignatureException::class);

        $parser->parse($payload, 'wrong-signature', []);
    }

    // ─── Test 4: Duplicate callback idempotency_key → no second state change ─

    public function test_duplicate_callback_produces_same_idempotency_key(): void
    {
        /** @var MockObject&ConfigurationServiceInterface $config */
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn($this->mockSecret);

        $verifier = new BankCallbackVerifier($config);
        $parser   = new MockBankCallbackParser($verifier);

        $attemptId = (string) Uuid::uuid7();
        $timestamp = (string) time();
        $payload   = json_encode([
            'transaction_ref' => $attemptId,
            'status'          => 'success',
            'timestamp'       => $timestamp,
        ]);
        $signature = hash_hmac('sha256', $payload, $this->mockSecret);

        $event1 = $parser->parse($payload, $signature, []);
        $event2 = $parser->parse($payload, $signature, []);

        // Same payload MUST produce same idempotency key — deduplication guarantee
        self::assertSame($event1->idempotencyKey, $event2->idempotencyKey);
        self::assertSame($attemptId . ':' . $timestamp, $event1->idempotencyKey);
    }

    // ─── Test 5: Out-of-order callback (terminal attempt) → marked duplicate ─

    public function test_out_of_order_check_uses_attempt_terminal_status(): void
    {
        // The job's out-of-order check examines the attempt status before calling handleOutcome.
        // Verified by the job implementation: terminateStatuses check at job:line~137.
        // Full integration test requires Docker.
        $this->markTestSkipped('T137: Out-of-order integration test requires Docker (live queue + DB).');
    }

    // ─── Test 6: payment.confirmed outbox event published exactly once ────────

    public function test_payment_confirmed_event_published_exactly_once(): void
    {
        $this->markTestSkipped('T137: Outbox publication requires Docker (live DB transaction).');
    }

    // ─── Test 7: Dead-letter creates reconciliation exception ─────────────────

    public function test_dead_letter_creates_reconciliation_exception(): void
    {
        $this->markTestSkipped('T137: Dead-letter handling requires Docker (queue + DB).');
    }

    // ─── Test 8: PaymentCallbackService throws for unknown provider ───────────

    public function test_callback_service_throws_for_unknown_provider(): void
    {
        $service = app(PaymentCallbackService::class);

        $this->expectException(\App\Modules\Platform\Domain\Exceptions\ProviderException::class);
        $this->expectExceptionMessageMatches('/No webhook parser registered/');

        $service->resolveParser('unknown_provider_xyz');
    }

    // ─── Test 9: PaymentCallbackService resolves mock_bank parser in dev env ──

    public function test_callback_service_resolves_mock_bank_parser_in_dev(): void
    {
        // In testing environment, mock_bank parser is registered
        $service = app(PaymentCallbackService::class);
        $parser  = $service->resolveParser('mock_bank');

        self::assertInstanceOf(MockBankCallbackParser::class, $parser);
    }
}
