<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\ConcurrencyTestHelper;
use Tests\Support\LedgerTestCase;

/**
 * T018, T023, T045: Concurrency and race condition tests for CommunityFuneral.
 *
 * Verifies that concurrent collection activations and payment confirmation replays
 * maintain strict financial and state integrity (Constitution Principle 4).
 */
final class FuneralConcurrencyTest extends LedgerTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment', 'community_services', 'notification'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['community_funeral.http_enabled' => true, 'queue.default' => 'sync']);
        $this->app->instance(OrganisationMembershipVerifier::class, new class implements OrganisationMembershipVerifier {
            public function assertActiveMembership(string $actorId, string $organisationId): void {}
        });
    }

    public function test_concurrent_activation_creates_single_snapshot_and_unique_obligations(): void
    {
        [$service, $context] = $this->setupApprovedCollection();

        $successCount = 0;
        $failCount = 0;

        $results = ConcurrencyTestHelper::runConcurrently(2, function () use ($service, $context, &$successCount, &$failCount) {
            try {
                $res = $service->activateCollection($context['collection_id'], $context['reviewer_id']);
                $successCount++;
                return $res;
            } catch (\Throwable $e) {
                $failCount++;
                return null;
            }
        });

        // Exactly one activation should succeed; the second should fail because status is no longer approved
        $this->assertSame(1, $successCount, 'Exactly one concurrent activation must succeed');
        $this->assertSame(1, $failCount, 'Second concurrent activation must be rejected');

        // Verify structural guarantees in DB
        $snapshotCount = DB::connection('community_services')
            ->table('community_services.funeral_eligibility_snapshots')
            ->where('collection_id', $context['collection_id'])->count();
        $this->assertSame(1, $snapshotCount, 'Exactly one eligibility snapshot must exist');

        $obligationCount = DB::connection('community_services')
            ->table('community_services.funeral_obligations')
            ->where('collection_id', $context['collection_id'])->count();
        $this->assertSame(1, $obligationCount, 'Exactly one obligation must exist for the eligible household');
    }

    public function test_concurrent_duplicate_confirmation_fulfils_obligation_once(): void
    {
        [$service, $context] = $this->setupApprovedCollection();
        $service->activateCollection($context['collection_id'], $context['reviewer_id']);

        $obligation = DB::connection('community_services')
            ->table('community_services.funeral_obligations')
            ->where('collection_id', $context['collection_id'])->first();

        $paymentEvent = $this->mockPaymentEvent($obligation->id, $context['organisation_id'], $context['customer_id']);

        // Concurrently dispatch duplicate confirmation event
        ConcurrencyTestHelper::runConcurrently(3, function () use ($service, $paymentEvent): void {
            $service->recordPaymentConfirmation($paymentEvent);
        });

        // Exactly 1 receipt must exist for this payment intent
        $receiptCount = DB::connection('community_services')
            ->table('community_services.funeral_payment_receipts')
            ->where('obligation_id', $obligation->id)
            ->where('payment_intent_id', $paymentEvent['payment_intent_id'])->count();
        $this->assertSame(1, $receiptCount);

        // Obligation is fulfilled exactly once
        $currentObligation = DB::connection('community_services')
            ->table('community_services.funeral_obligations')
            ->where('id', $obligation->id)->first();
        $this->assertSame('confirmed', $currentObligation->status);
        $this->assertSame('fulfilled', $currentObligation->fulfilment_status);
    }

    /** @return array{0:FuneralAssistanceService, 1:array<string, string>} */
    private function setupApprovedCollection(): array
    {
        $manager = $this->makeAdminWithPermission('community.funeral.manage');
        $reviewer = $this->makeAdminWithPermission('community.funeral.review');
        $customer = $this->makeActorWithPermission('CUSTOMER', 'community.funeral.household.read');
        $orgId = (string) Uuid::uuid7();

        $beneficiary = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $orgId,
            'account_type' => 'bank_account', 'account_number' => 'ACC-CONC-TEST',
            'account_holder_name' => 'Beneficiary Conc', 'currency_code' => 'BWP',
            'status' => PayoutBeneficiaryStatus::Verified, 'verified_at' => now(), 'verification_reference' => 'REF-CONC',
            'created_by' => $manager->id,
        ]);

        $service = app(FuneralAssistanceService::class);
        $comm = $service->createCommunity($orgId, 'Conc Test Community', $manager->id);
        $service->addCommunityStaff($comm['id'], $reviewer->id, 'reviewer', $manager->id);

        $hh = $service->createHousehold($comm['id'], 'HH-CONC-01', $manager->id);
        $service->reviewHousehold($hh['id'], 'eligible', null, $reviewer->id);

        $rep = $service->addRepresentative($hh['id'], $customer->id, 'restricted-evidence-conc', $manager->id);
        $service->verifyRepresentative($hh['id'], $rep['id'], $reviewer->id);

        $coll = $service->createCollection($comm['id'], $beneficiary->id, 'evidence-conc-coll', $manager->id);
        $service->approveCollection($coll['id'], $reviewer->id);

        return [$service, [
            'collection_id' => $coll['id'],
            'organisation_id' => $orgId,
            'reviewer_id' => $reviewer->id,
            'customer_id' => $customer->id,
        ]];
    }

    /** @return array<string, int|string> */
    private function mockPaymentEvent(string $obligationId, string $orgId, string $payerId): array
    {
        $intentId = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $providerId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();
        $providerCode = 'prov_'.substr($providerId, 0, 8);

        DB::connection('payment')->table('payment.payment_providers')->insert([
            'id' => $providerId, 'code' => $providerCode, 'display_name' => 'Conc Provider',
            'adapter_identifier' => 'conc_test', 'adapter_version' => '1.0.0', 'environment' => 'mock',
            'is_enabled' => false, 'is_suspended' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $providerId, 'merchant_account_reference' => 'ACC-CONC',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id' => $intentId, 'reference' => 'INT-'.substr($intentId, 0, 8),
            'purpose_type' => 'funeral_contribution', 'purpose_reference' => $obligationId,
            'organisation_id' => $orgId, 'payer_actor_id' => $payerId,
            'currency_code' => 'BWP', 'amount_minor' => 2000, 'status' => 'succeeded',
            'accounting_status' => 'ledger_posted', 'fulfilment_status' => 'pending',
            'version' => 1, 'idempotency_key' => 'idem-'.$intentId, 'correlation_id' => $intentId,
            'metadata' => '{}', 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id' => $attemptId, 'payment_intent_id' => $intentId, 'provider_code' => $providerCode,
            'provider_account_id' => $accountId, 'status' => 'succeeded', 'idempotency_key' => 'att-'.$attemptId,
            'operation_id' => (string) Uuid::uuid7(), 'fee_minor' => 0, 'initiated_at' => now(),
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'purpose_type' => 'funeral_contribution', 'purpose_reference' => $obligationId,
            'payment_intent_id' => $intentId, 'payment_attempt_id' => $attemptId,
            'organisation_id' => $orgId, 'payer_actor_id' => $payerId,
            'amount_minor' => 2000, 'currency_code' => 'BWP', 'fee_minor' => 0,
            'correlation_id' => $intentId, 'occurred_at' => now()->toIso8601String(),
        ];
    }
}
