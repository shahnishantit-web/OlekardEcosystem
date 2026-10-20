<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Funding\Infrastructure\Models\UnmatchedDepositModel;

final class BankMatchingTest extends FundingTestCase
{
    private function createAwaitingMatchTransaction(string $bankRef): FundingTransactionModel
    {
        $tx                          = new FundingTransactionModel();
        $tx->id                      = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->customer_id             = $this->customerId;
        $tx->wallet_id               = $this->walletId;
        $tx->pocket_currency_code    = 'BWP';
        $tx->funding_method          = FundingMethod::BankTransfer;
        $tx->status                  = FundingStatus::AwaitingMatch;
        $tx->gross_amount            = 10000;
        $tx->fee_amount              = 100;
        $tx->net_amount              = 9900;
        $tx->transaction_reference   = 'FND-BANK-' . uniqid();
        $tx->bank_reference_code     = $bankRef;
        $tx->bank_reference_expires_at = now()->addHours(72);
        $tx->correlation_id          = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->save();

        return $tx;
    }

    // ── FR-E08-055 — FR-E08-062: Bank notification matching ─────────────────

    public function test_matching_bank_notification_completes_transaction(): void
    {
        $bankRef = 'OLK-TESTAB';
        $tx      = $this->createAwaitingMatchTransaction($bankRef);

        $response = $this->postJson('/api/v1/funding/bank/notifications', [
            'bank_notification_id' => 'NOTIF-001',
            'bank_reference_code'  => $bankRef,
            'amount'               => 10000,
            'currency_code'        => 'BWP',
        ], ['X-Bank-Api-Key' => 'test-bank-key']);

        $response->assertStatus(200)->assertJson(['acknowledged' => true]);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Completed->value,
        ]);
    }

    public function test_unmatched_notification_stored_as_unmatched_deposit(): void
    {
        $response = $this->postJson('/api/v1/funding/bank/notifications', [
            'bank_notification_id' => 'NOTIF-UNMATCHED-001',
            'bank_reference_code'  => 'OLK-UNKNOWN',
            'amount'               => 5000,
            'currency_code'        => 'BWP',
        ], ['X-Bank-Api-Key' => 'test-bank-key']);

        $response->assertStatus(200);

        $this->assertDatabaseHas('funding.funding_unmatched_deposits', [
            'bank_reference_code'  => 'OLK-UNKNOWN',
            'resolution_status'    => 'unresolved',
        ]);
    }

    public function test_duplicate_bank_notification_is_idempotent(): void
    {
        $bankRef = 'OLK-IDEM01';
        $this->createAwaitingMatchTransaction($bankRef);

        $payload = [
            'bank_notification_id' => 'NOTIF-DUP-001',
            'bank_reference_code'  => $bankRef,
            'amount'               => 10000,
            'currency_code'        => 'BWP',
        ];
        $headers = ['X-Bank-Api-Key' => 'test-bank-key'];

        $this->postJson('/api/v1/funding/bank/notifications', $payload, $headers)->assertStatus(200);
        $this->postJson('/api/v1/funding/bank/notifications', $payload, $headers)->assertStatus(200);

        // Only one journal should be posted
        $this->assertDatabaseCount('ledger.journals', 1);
    }

    public function test_amount_mismatch_stores_unmatched_deposit(): void
    {
        $bankRef = 'OLK-MISMATCH';
        $this->createAwaitingMatchTransaction($bankRef);

        $this->postJson('/api/v1/funding/bank/notifications', [
            'bank_notification_id' => 'NOTIF-MISMATCH-001',
            'bank_reference_code'  => $bankRef,
            'amount'               => 999,   // expected 10000
            'currency_code'        => 'BWP',
        ], ['X-Bank-Api-Key' => 'test-bank-key'])
            ->assertStatus(200);

        $this->assertDatabaseHas('funding.funding_unmatched_deposits', [
            'bank_reference_code' => $bankRef,
        ]);
    }
}
