<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment;

use App\Modules\Payment\Application\Services\PaymentNotificationValidator;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Infrastructure\Adapters\AccountMockWebhookParser;
use App\Modules\Payment\Infrastructure\Adapters\MockBankPaymentAdapter;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class P10CallbackContractTest extends TestCase
{
    private const ATTEMPT = '019a0011-0000-7000-8000-000000000001';

    private function account(): PaymentProviderAccountModel
    {
        $account = new PaymentProviderAccountModel(['id' => '019a0011-0000-7000-8000-000000000002', 'merchant_account_reference' => 'merchant-a', 'callback_secret_reference' => 'P10_TEST_CALLBACK_SECRET']);
        $account->setRelation('provider', new PaymentProviderModel(['code' => 'mock-bank-a', 'environment' => 'mock', 'adapter_identifier' => 'mock_bank']));
        return $account;
    }

    private function parser(?PaymentProviderAccountModel $account = null, string $environment = 'mock'): AccountMockWebhookParser
    {
        $secret = $this->createMock(SecretProviderInterface::class);
        $secret->method('get')->with('P10_TEST_CALLBACK_SECRET')->willReturn('test-only-account-secret');
        return new AccountMockWebhookParser($account ?? $this->account(), 'mock-bank-a', $environment, $secret);
    }

    private function body(array $replace = []): string
    {
        return json_encode(array_replace(['event_id' => 'test-event-1', 'transaction_ref' => self::ATTEMPT,
            'merchant_account' => 'merchant-a', 'amount' => 100, 'currency' => 'BWP', 'status' => 'success',
            'tenant_id' => 'untrusted-tenant'], $replace), JSON_THROW_ON_ERROR);
    }

    public function test_raw_bytes_and_trusted_context_are_preserved(): void
    {
        $body = $this->body();
        $event = $this->parser()->parse($body, hash_hmac('sha256', $body, 'test-only-account-secret'), []);
        $this->assertSame($body, $event->rawPayload);
        $this->assertSame($this->account()->id, $event->metadata['payment_account_id']);
        $this->assertArrayNotHasKey('tenant_id', $event->metadata);
        $this->assertTrue($event->metadata['test_only']);
    }

    #[DataProvider('badSignatures')]
    public function test_invalid_or_tampered_signature_is_denied(string $signature, bool $tamper): void
    {
        $body = $this->body();
        if ($signature === 'valid') $signature = hash_hmac('sha256', $body, 'test-only-account-secret');
        $this->expectException(WebhookSignatureException::class);
        $this->parser()->parse($body.($tamper ? ' ' : ''), $signature, []);
    }

    public static function badSignatures(): array
    {
        return [['', false], ['incorrect', false], ['valid', true]];
    }

    public function test_event_identity_is_scoped_to_account_and_environment(): void
    {
        $body = $this->body(); $signature = hash_hmac('sha256', $body, 'test-only-account-secret');
        $first = $this->parser()->parse($body, $signature, []);
        $other = $this->account(); $other->id = '019a0011-0000-7000-8000-000000000003';
        $this->assertNotSame($first->idempotencyKey, $this->parser($other)->parse($body, $signature, [])->idempotencyKey);
        $this->assertNotSame($first->idempotencyKey, $this->parser(null, 'sandbox')->parse($body, $signature, [])->idempotencyKey);
        $this->assertSame($first->idempotencyKey, $this->parser()->parse($body, $signature, [])->idempotencyKey);
    }

    #[DataProvider('mismatches')]
    public function test_semantic_mismatches_never_reach_financial_processing(string $field, mixed $value, ?string $expected): void
    {
        $account = $this->account();
        $attempt = new PaymentAttemptModel(['id' => self::ATTEMPT, 'payment_intent_id' => 'intent-a', 'provider_account_id' => $account->id, 'provider_code' => 'mock-bank-a', 'provider_reference_id' => self::ATTEMPT]);
        $intent = new PaymentIntentModel(['id' => 'intent-a', 'amount_minor' => 100, 'currency_code' => 'BWP']);
        $notification = ['entity_id' => self::ATTEMPT, 'provider_reference' => self::ATTEMPT, 'status' => 'success', 'context' => [
            'payment_account_id' => $account->id, 'payment_provider_code' => 'mock-bank-a', 'payment_environment' => 'mock',
            'merchant_account' => 'merchant-a', 'amount_minor' => 100, 'currency' => 'BWP']];
        if ($field !== '') $notification['context'][$field] = $value;
        $this->assertSame($expected, (new PaymentNotificationValidator())->validate($notification, $attempt, $intent, $account));
    }

    public static function mismatches(): array
    {
        return [['', null, null], ['payment_account_id', 'other-account', 'account_mismatch'],
            ['payment_provider_code', 'other-bank', 'account_mismatch'], ['payment_environment', 'production', 'account_mismatch'],
            ['merchant_account', 'merchant-b', 'account_mismatch'], ['amount_minor', 101, 'money_mismatch'],
            ['amount_minor', '100', 'money_mismatch'], ['currency', 'USD', 'money_mismatch']];
    }

    public function test_existing_composite_preserves_capabilities_but_does_not_imply_payout(): void
    {
        $adapter = $this->createMock(PaymentAdapterInterface::class);
        $this->assertSame($adapter, PaymentCapabilities::initiate($adapter));
        $this->assertSame($adapter, PaymentCapabilities::query($adapter));
        $this->assertSame($adapter, PaymentCapabilities::refund($adapter));
        $this->expectException(CapabilityUnsupportedException::class);
        PaymentCapabilities::payout($adapter);
    }

    #[DataProvider('capabilities')]
    public function test_unsupported_capability_rejects_explicitly(string $capability): void
    {
        $this->expectException(CapabilityUnsupportedException::class);
        PaymentCapabilities::$capability(new \stdClass());
    }

    public static function capabilities(): array { return [['initiate'], ['query'], ['refund'], ['payout']]; }

    public function test_query_only_adapter_does_not_need_the_legacy_composite(): void
    {
        $adapter = $this->createMock(\App\Modules\Payment\Domain\Contracts\QueriesPayment::class);
        $this->assertSame($adapter, PaymentCapabilities::query($adapter));
        $this->expectException(CapabilityUnsupportedException::class);
        PaymentCapabilities::refund($adapter);
    }

    #[DataProvider('guardedOperations')]
    public function test_cached_mock_cannot_execute_after_production_environment_change(string $operation): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $adapter = new MockBankPaymentAdapter($this->createMock(AuditServiceInterface::class), $config, $this->createMock(PaymentAttemptRepositoryInterface::class));
        $this->app->instance('env', 'production');
        $this->expectException(ProductionBlockedException::class);
        match ($operation) {
            'initiate' => $adapter->initiatePayment(new PaymentInitiateRequest('intent', self::ATTEMPT, 100, 'BWP', 'payer', 'correlation', 'idem')),
            'query' => $adapter->queryPaymentStatus(self::ATTEMPT),
            'refund' => $adapter->initiateRefund(new PaymentRefundRequest('intent', self::ATTEMPT, 'refund', 100, 'BWP', 'correlation', 'idem')),
            'cancel' => $adapter->cancelPayment(self::ATTEMPT),
        };
    }

    public static function guardedOperations(): array { return [['initiate'], ['query'], ['refund'], ['cancel']]; }

    public function test_cached_parser_cannot_verify_in_production(): void
    {
        $parser = $this->parser(); $this->app->instance('env', 'production');
        $this->expectException(ProductionBlockedException::class);
        $parser->parse($this->body(), '', []);
    }

    public function test_mock_provider_activation_is_blocked_in_production_before_database_write(): void
    {
        $provider = new PaymentProviderModel(['code' => 'unsafe-mock', 'adapter_identifier' => 'mock_bank', 'is_enabled' => true, 'environment' => 'production']);
        $this->app->instance('env', 'production');
        $this->expectException(ProductionBlockedException::class);
        $provider->save();
    }

    #[DataProvider('mockScenarios')]
    public function test_development_scenarios_preserve_unresolved_outcomes(string $scenario, string $expected): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnCallback(fn ($key, $default) => $key === 'payment.mock_bank.default_outcome' ? $scenario : $default);
        $adapter = new MockBankPaymentAdapter($this->createMock(AuditServiceInterface::class), $config, $this->createMock(PaymentAttemptRepositoryInterface::class));
        $this->assertSame($expected, $adapter->queryPaymentStatus(self::ATTEMPT)->status->value);
    }

    public static function mockScenarios(): array
    {
        return [['success', 'succeeded'], ['failed', 'failed'], ['unknown', 'unknown'], ['timeout', 'unknown'], ['pending', 'awaiting_action']];
    }
}
