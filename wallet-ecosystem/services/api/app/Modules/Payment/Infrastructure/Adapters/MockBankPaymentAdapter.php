<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentCancelResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * T101: Mock bank adapter for development and testing ONLY.
 * Permanently blocked in production and staging environments.
 * All outcomes are configurable via config('payment.mock_bank.*').
 * All log entries MUST be prefixed with [MOCK].
 */
final class MockBankPaymentAdapter extends AbstractBankPaymentAdapter
{
    public function __construct(
        AuditServiceInterface              $audit,
        ConfigurationServiceInterface      $config,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
    ) {
        parent::__construct($audit, $config);

        // T101: Permanent production guard — throws immediately if not local/testing
        DevelopmentPaymentGuard::check();
    }

    public function getProviderIdentifier(): string
    {
        return 'mock_bank';
    }

    public function getCapabilities(): ProviderCapabilitySet
    {
        return ProviderCapabilitySet::of(
            ProviderCapabilityEnum::InitiatePayment,
            ProviderCapabilityEnum::QueryTransactionStatus,
            ProviderCapabilityEnum::ReversePriorPayment,
            ProviderCapabilityEnum::ReceiveWebhook,
        );
    }

    protected function getBaseUrl(): string
    {
        return 'http://localhost/mock-bank';
    }

    /**
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer mock-test-key'];
    }

    protected function getSignatureAlgorithm(): string
    {
        return 'sha256';
    }

    protected function getCallbackSecretReference(): string
    {
        return 'payment.mock_bank.callback_secret';
    }

    /**
     * T101: Returns a redirect URL to the local mock bank checkout page.
     * Status = awaiting_action (redirect flow).
     */
    public function initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult
    {
        DevelopmentPaymentGuard::check();
        Log::info('[MOCK] MockBankPaymentAdapter::initiatePayment', [
            'attempt_id'   => $request->paymentAttemptId,
            'amount_minor' => $request->amountMinor,
            'currency'     => $request->currencyCode,
        ]);

        $redirectUrl = 'http://localhost/mock-bank/checkout?ref='.$request->paymentAttemptId.'&test=1';

        return new PaymentAttemptResult(
            status:          PaymentAttemptStatus::AwaitingAction,
            feeMinor:        0,
            providerReferenceId: $request->paymentAttemptId,
            nextActionType:  'redirect',
            nextActionData:  ['redirect_url' => $redirectUrl, 'test_only' => true, 'label' => 'Development mock bank — no real money'],
        );
    }

    /**
     * T107: Queries mock attempt status with optional auto-confirm after a configured delay.
     * providerReferenceId == paymentAttemptId for mock bank.
     * If 'payment.mock_bank.auto_confirm_seconds' > 0 and the attempt is still awaiting,
     * auto-confirms once that many seconds have elapsed (simulates async bank confirmation).
     */
    public function queryPaymentStatus(string $providerReferenceId): PaymentStatusResult
    {
        DevelopmentPaymentGuard::check();
        Log::info('[MOCK] MockBankPaymentAdapter::queryPaymentStatus', [
            'provider_reference_id' => $providerReferenceId,
        ]);

        $autoConfirmSeconds = (int) $this->config->getOrDefault('payment.mock_bank.auto_confirm_seconds', 0);

        if ($autoConfirmSeconds > 0) {
            $attempt = $this->attemptRepository->findById($providerReferenceId);

            if ($attempt !== null && $attempt->initiated_at instanceof \DateTimeImmutable) {
                $elapsed = (new \DateTimeImmutable())->getTimestamp() - $attempt->initiated_at->getTimestamp();

                if ($elapsed >= $autoConfirmSeconds) {
                    Log::info('[MOCK] Auto-confirming attempt after delay', [
                        'attempt_id'          => $providerReferenceId,
                        'auto_confirm_seconds' => $autoConfirmSeconds,
                        'elapsed_seconds'      => $elapsed,
                    ]);

                    return new PaymentStatusResult(
                        status:              PaymentAttemptStatus::Succeeded,
                        providerReferenceId: $providerReferenceId,
                    );
                }
            }
        }

        $defaultOutcome = $this->config->getOrDefault('payment.mock_bank.default_outcome', 'success');

        $status = match ($defaultOutcome) {
            'success'  => PaymentAttemptStatus::Succeeded,
            'failed'   => PaymentAttemptStatus::Failed,
            'unknown', 'timeout' => PaymentAttemptStatus::Unknown,
            'pending' => PaymentAttemptStatus::AwaitingAction,
            default => throw new \InvalidArgumentException('Unknown development mock bank scenario.'),
        };

        return new PaymentStatusResult(
            status:              $status,
            providerReferenceId: $providerReferenceId,
        );
    }

    /**
     * T101: Returns a mock refund reference. All operations log [MOCK] prefix.
     */
    public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult
    {
        DevelopmentPaymentGuard::check();
        $mockRefundRef = 'MOCK-REFUND-'.(string) Uuid::uuid7();

        Log::info('[MOCK] MockBankPaymentAdapter::initiateRefund', [
            'refund_id'     => $request->refundId,
            'amount_minor'  => $request->amountMinor,
            'mock_refund_ref' => $mockRefundRef,
        ]);

        return new PaymentRefundResult(
            status:               PaymentRefundStatus::Succeeded,
            providerRefundReference: $mockRefundRef,
        );
    }

    /**
     * T101: Cancel payment — returns succeeded for mock.
     */
    public function cancelPayment(string $providerReferenceId): PaymentCancelResult
    {
        DevelopmentPaymentGuard::check();
        Log::info('[MOCK] MockBankPaymentAdapter::cancelPayment', [
            'provider_reference_id' => $providerReferenceId,
        ]);

        return new PaymentCancelResult(cancelled: true);
    }
}
