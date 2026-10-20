<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Tests\Feature\Modules\Funding\FundingTestCase;

final class ExpireStaleTransactionsCommandTest extends FundingTestCase
{
    private function makeStaleTx(FundingStatus $status, array $overrides = []): FundingTransactionModel
    {
        $tx                          = new FundingTransactionModel();
        $tx->id                      = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->customer_id             = $this->customerId;
        $tx->wallet_id               = $this->walletId;
        $tx->pocket_currency_code    = 'BWP';
        $tx->funding_method          = \App\Modules\Funding\Domain\Enums\FundingMethod::MobileMoney;
        $tx->status                  = $status;
        $tx->gross_amount            = 10000;
        $tx->fee_amount              = 150;
        $tx->net_amount              = 9850;
        $tx->transaction_reference   = 'FND-STALE-' . uniqid();
        $tx->correlation_id          = (string) \Ramsey\Uuid\Uuid::uuid7();

        foreach ($overrides as $k => $v) {
            $tx->$k = $v;
        }

        $tx->save();
        return $tx;
    }

    public function test_stale_processing_transaction_is_failed(): void
    {
        $tx = $this->makeStaleTx(FundingStatus::Processing, [
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('funding:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'             => $tx->id,
            'status'         => FundingStatus::Failed->value,
            'failure_reason' => FundingFailureReason::ProcessingTimeout->value,
        ]);
    }

    public function test_expired_awaiting_match_transaction_is_failed(): void
    {
        $tx = $this->makeStaleTx(FundingStatus::AwaitingMatch, [
            'bank_reference_code'       => 'OLK-EXPIRED',
            'bank_reference_expires_at' => now()->subHours(1),
        ]);

        $this->artisan('funding:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'             => $tx->id,
            'status'         => FundingStatus::Failed->value,
            'failure_reason' => FundingFailureReason::BankReferenceExpired->value,
        ]);
    }

    public function test_fresh_processing_transaction_is_not_expired(): void
    {
        $tx = $this->makeStaleTx(FundingStatus::Processing, [
            'created_at' => now()->subMinutes(2), // well within timeout
        ]);

        $this->artisan('funding:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Processing->value,
        ]);
    }

    public function test_completed_transaction_is_not_modified(): void
    {
        $tx = $this->makeStaleTx(FundingStatus::Completed, [
            'created_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);

        $this->artisan('funding:expire-stale')->assertExitCode(0);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Completed->value,
        ]);
    }
}
