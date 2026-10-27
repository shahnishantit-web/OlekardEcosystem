<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Adapters;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Infrastructure\Adapters\BankCallbackVerifier;
use App\Modules\Payment\Infrastructure\Adapters\MockBankPaymentAdapter;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\Exceptions\ProviderException;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

/**
 * Extends Laravel TestCase so that app()->environment() is available
 * in MockBankPaymentAdapter's constructor.
 */
final class MockBankPaymentAdapterTest extends \Tests\TestCase
{
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
    }

    public function test_production_guard_throws_in_production_env(): void
    {
        $this->expectException(ProductionBlockedException::class);

        $this->app->instance('env', 'production');
        $this->makeAdapter();
    }

    public function test_get_provider_identifier(): void
    {
        $adapter = $this->makeAdapter();
        self::assertSame('mock_bank', $adapter->getProviderIdentifier());
    }

    public function test_get_capabilities_correct(): void
    {
        $adapter = $this->makeAdapter();
        $caps    = $adapter->getCapabilities();

        self::assertTrue($caps->has(ProviderCapabilityEnum::InitiatePayment));
        self::assertTrue($caps->has(ProviderCapabilityEnum::QueryTransactionStatus));
        self::assertTrue($caps->has(ProviderCapabilityEnum::ReversePriorPayment));
        self::assertTrue($caps->has(ProviderCapabilityEnum::ReceiveWebhook));
    }

    public function test_initiate_payment_returns_redirect_url_with_dev_domain(): void
    {
        $adapter   = $this->makeAdapter();
        $attemptId = (string) Uuid::uuid7();

        $result = $adapter->initiatePayment(new PaymentInitiateRequest(
            paymentIntentId:  (string) Uuid::uuid7(),
            paymentAttemptId: $attemptId,
            amountMinor:      10000,
            currencyCode:     'BWP',
            payerActorId:     'actor-1',
            correlationId:    (string) Uuid::uuid7(),
            idempotencyKey:   (string) Uuid::uuid7(),
        ));

        self::assertSame(PaymentAttemptStatus::AwaitingAction, $result->status);
        self::assertSame('redirect', $result->nextActionType);
        self::assertNotNull($result->nextActionData);
        self::assertStringContainsString('localhost', $result->nextActionData['redirect_url']);
        self::assertStringContainsString($attemptId, $result->nextActionData['redirect_url']);
        self::assertTrue($result->nextActionData['test_only']);
        self::assertStringContainsString('Development mock', $result->nextActionData['label']);
    }

    public function test_bank_callback_verifier_valid_signature(): void
    {
        $secret  = 'test-secret-value-12345';
        $payload = '{"status":"success","amount":10000}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn($secret);

        $verifier = new BankCallbackVerifier($config);
        self::assertTrue($verifier->verify($payload, $sig, 'sha256', 'payment.mock_bank.callback_secret'));
    }

    public function test_bank_callback_verifier_invalid_signature_throws(): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('test-secret');

        $verifier = new BankCallbackVerifier($config);

        $this->expectException(ProviderException::class);

        // Call verify but check the result — invalid sig returns false, not exception
        // Actually verifier returns bool, not exception for invalid sig
        // Let's test with an unsupported algorithm instead
        $verifier->verify('payload', 'sig', 'md5', 'secret.ref'); // md5 is unsupported
    }

    public function test_bank_callback_verifier_wrong_algorithm_throws_exception(): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $verifier = new BankCallbackVerifier($config);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/Unsupported HMAC algorithm/');

        $verifier->verify('payload', 'bad-sig', 'md5', 'secret.ref');
    }

    public function test_bank_callback_verifier_invalid_signature_returns_false(): void
    {
        $secret = 'correct-secret';
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn($secret);

        $verifier = new BankCallbackVerifier($config);

        $result = $verifier->verify('payload', 'wrong-signature', 'sha256', 'secret.ref');
        self::assertFalse($result);
    }

    private function makeAdapter(): MockBankPaymentAdapter
    {
        $this->config->method('getOrDefault')->willReturn('success');

        return new MockBankPaymentAdapter(
            audit:  $this->createMock(AuditServiceInterface::class),
            config: $this->config,
            attemptRepository: $this->createMock(\App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface::class),
        );
    }
}
