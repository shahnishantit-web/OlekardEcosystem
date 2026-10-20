<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Funding;

use App\Modules\Funding\Application\Services\BankNotificationService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Tests\Feature\Modules\Funding\FundingTestCase;
use Tests\Support\ConcurrencyTestHelper;

/**
 * Verifies that concurrent bank notifications for the same bank reference code
 * result in exactly one credit posting (not double-credits).
 */
final class BankMatchingConcurrencyTest extends FundingTestCase
{
    public function test_concurrent_bank_notifications_with_same_reference_credit_once(): void
    {
        $bankRef = 'OLK-CONCUR';

        $tx                          = new FundingTransactionModel();
        $tx->id                      = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->customer_id             = $this->customerId;
        $tx->wallet_id               = $this->walletId;
        $tx->pocket_currency_code    = 'BWP';
        $tx->funding_method          = FundingMethod::BankTransfer;
        $tx->status                  = FundingStatus::AwaitingMatch;
        $tx->gross_amount            = 10000;
        $tx->fee_amount              = 0;
        $tx->net_amount              = 10000;
        $tx->transaction_reference   = 'FND-BANK-CONCUR';
        $tx->bank_reference_code     = $bankRef;
        $tx->bank_reference_expires_at = now()->addHours(72);
        $tx->correlation_id          = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->save();

        $txId = $tx->id;

        // Two concurrent notifications for the same bank reference
        ConcurrencyTestHelper::runConcurrent(2, function () use ($bankRef): void {
            app(BankNotificationService::class)->handle([
                'bank_notification_id' => 'NOTIF-CONCUR-001', // same ID — idempotency key
                'bank_reference_code'  => $bankRef,
                'amount'               => 10000,
                'currency_code'        => 'BWP',
                'correlation_id'       => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'raw_payload'          => [],
            ]);
        });

        // Exactly one journal should have been posted
        $journalCount = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('ledger.journals')
            ->where('transaction_reference', 'FND-BANK-CONCUR')
            ->count();

        $this->assertSame(1, $journalCount, 'Concurrent bank notifications must post exactly one journal entry');

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $txId,
            'status' => FundingStatus::Completed->value,
        ]);
    }
}
