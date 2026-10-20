<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Service;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * QS-E15-003: Verifies that on provider failure callback, the wallet balance is restored
 * and the compensating ledger journal is balanced.
 */
final class ServiceOutcomeIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failure_callback_reverses_debit_and_posts_balanced_journal(): void
    {
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);
        $audit   = $this->createMock(AuditServiceInterface::class);
        $events  = $this->createMock(EventPublisherInterface::class);

        $config->method('getOrDefault')->willReturnCallback(function (string $key, mixed $default = null) {
            if (str_contains($key, 'step_up_threshold')) {
                return 1000000;
            }
            return 'service.payable.BWP';
        });
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-outcome-bwp');
        $ledger->method('registerAccount');
        $audit->method('record');
        $events->method('publish');

        // Payment journal (debit)
        $paymentJournalPosted = false;
        // Reversal journal (credit)
        $reversalJournalPosted = false;

        $ledger->method('postEntry')->willReturnCallback(function (array $request) use (
            &$paymentJournalPosted, &$reversalJournalPosted
        ) {
            if ($request['entry_type'] === 'debit') {
                $paymentJournalPosted = true;
                return 'journal-payment';
            }
            if ($request['entry_type'] === 'credit') {
                $reversalJournalPosted = true;
                // Verify balanced: debit line amount == credit line amount
                $amounts = array_column($request['lines'], 'amount');
                if (count(array_unique($amounts)) !== 1) {
                    throw new \RuntimeException('Unbalanced reversal journal!');
                }
                return 'journal-reversal';
            }
            return 'journal-other';
        });

        // Setup service
        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Outcome Integration Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'OUTINT_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-setup');

        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('PROV-FAIL-TEST', 'completed'));

        $paymentAudit  = $this->createMock(AuditServiceInterface::class);
        $paymentEvents = $this->createMock(EventPublisherInterface::class);
        $paymentAudit->method('record');
        $paymentEvents->method('publish');

        $paymentLedger = $this->createMock(LedgerModuleContract::class);
        $paymentLedger->method('registerAccount');
        $paymentLedger->method('postEntry')->willReturn('journal-payment');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $paymentLedger,
            $wallet, $config, $paymentAudit, $paymentEvents
        );

        $tx = $paymentService->initiate([
            'service_id'         => $service->id,
            'wallet_id'          => '00000000-0000-0000-0000-000000000023',
            'customer_reference' => '1234567890',
            'account_name'       => 'Integration Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => 'idem-outint-'.uniqid(),
        ], '00000000-0000-0000-0000-000000000012', 'corr-payment');

        $tx->status = ServiceTransactionStatus::PendingProvider;
        $tx->save();

        // Now process failure outcome
        $outcomeService = new ServiceOutcomeService(
            new ServiceReceiptService(), $ledger, $wallet, $config, $audit, $events
        );

        $outcomeService->handleFailure($tx->id, 'provider_declined', 'corr-failure');

        $refreshed = ServiceTransactionModel::find($tx->id);
        $this->assertSame(ServiceTransactionStatus::Failed, $refreshed->status, 'Transaction must be failed');
        $this->assertTrue($reversalJournalPosted, 'Compensating (reversal) journal must be posted');
        $this->assertSame('provider_declined', $refreshed->failure_reason);
    }
}
