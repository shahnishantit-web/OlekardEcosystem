<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * E27 — FR-005: Duplicate provider callbacks are deduplicated using provider_reference_id.
 *
 * Verifies that replaying a provider callback with the same provider_reference_id
 * returns HTTP 200 without creating a second ledger posting or changing the
 * already-completed transaction.
 */
final class DuplicateCallbackTest extends FundingTestCase
{
    private function buildCallbackPayload(string $txRef, string $providerRef, string $status = 'success'): array
    {
        return [
            'transaction_reference'   => $txRef,
            'provider_transaction_id' => $providerRef,
            'status'                  => $status,
            'amount'                  => 10000,
            'currency'                => 'BWP',
        ];
    }

    private function computeHmacSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), 'mock-secret-change-in-production');
    }

    private function createCompletedTransaction(string $providerRef): FundingTransactionModel
    {
        $tx                        = new FundingTransactionModel();
        $tx->id                    = (string) Uuid::uuid7();
        $tx->customer_id           = $this->customerId;
        $tx->wallet_id             = $this->walletId;
        $tx->pocket_currency_code  = 'BWP';
        $tx->funding_method        = FundingMethod::MobileMoney;
        $tx->status                = FundingStatus::Completed;
        $tx->gross_amount          = 10000;
        $tx->fee_amount            = 150;
        $tx->net_amount            = 9850;
        $tx->transaction_reference = 'FND-TEST-DUP-' . uniqid();
        $tx->provider_reference_id = $providerRef;
        $tx->correlation_id        = (string) Uuid::uuid7();
        $tx->save();

        return $tx;
    }

    // ── FR-005: Duplicate callback acknowledged without second credit ────────

    public function test_duplicate_callback_returns_200_without_second_ledger_entry(): void
    {
        $providerRef = 'PROVIDER-DUP-' . (string) Uuid::uuid7();
        $tx          = $this->createCompletedTransaction($providerRef);

        // Count journal entries before the duplicate callback
        $entriesBefore = DB::connection('platform')->table('ledger.journal_entries')
            ->where('source_reference', $tx->id)
            ->count();

        $payload = $this->buildCallbackPayload($tx->transaction_reference, $providerRef, 'success');
        $sig     = $this->computeHmacSignature($payload);

        // Replay the same callback
        $response = $this->postJson('/api/v1/funding/callbacks/mock', $payload, [
            'X-Signature' => $sig,
        ]);

        $response->assertStatus(200);

        // No additional journal entries must have been created
        $entriesAfter = DB::connection('platform')->table('ledger.journal_entries')
            ->where('source_reference', $tx->id)
            ->count();

        $this->assertEquals(
            $entriesBefore,
            $entriesAfter,
            'Duplicate provider callback must not create additional ledger entries (FR-005)'
        );
    }

    // ── FR-005: Transaction remains in Completed state after duplicate ────────

    public function test_completed_transaction_stays_completed_after_duplicate_callback(): void
    {
        $providerRef = 'PROVIDER-DUP2-' . (string) Uuid::uuid7();
        $tx          = $this->createCompletedTransaction($providerRef);

        $payload = $this->buildCallbackPayload($tx->transaction_reference, $providerRef, 'success');
        $sig     = $this->computeHmacSignature($payload);

        $this->postJson('/api/v1/funding/callbacks/mock', $payload, [
            'X-Signature' => $sig,
        ])->assertStatus(200);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'                 => $tx->id,
            'status'             => FundingStatus::Completed->value,
            'provider_reference_id' => $providerRef,
        ]);
    }
}
