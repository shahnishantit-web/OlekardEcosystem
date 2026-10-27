<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Application\FuneralObligationResolver;
use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class FuneralReportingAndArchiveHttpTest extends IntegrationTestCase
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

    public function test_collection_close_disallows_checkout_and_exposes_privacy_safe_aggregate_report(): void
    {
        [$manager, $reviewer, $customer, $collectionId, $obligationId] = $this->setupActiveCollection();
        $url = '/api/v1/community/funeral';

        // 1. Close collection
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $closeRes = $this->postJson("$url/collections/$collectionId/close")->assertOk();
        $this->assertSame(0, $closeRes->json('data.confirmed_gross_minor'));

        // 2. Resolver rejects checkout for closed collection
        $this->expectException(PurposeNotPayableException::class);
        app(FuneralObligationResolver::class)->resolve($obligationId, $customer->id, (string) Uuid::uuid7());
    }

    public function test_aggregate_report_privacy_and_maker_checker_archive_guard(): void
    {
        [$manager, $reviewer, $customer, $collectionId, $obligationId] = $this->setupActiveCollection();
        $reporter = $this->makeAdminWithPermission('community.funeral.report');
        $url = '/api/v1/community/funeral';

        // Close collection
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/close")->assertOk();

        // Simulate late confirmation creating unresolved liability
        $service = app(FuneralAssistanceService::class);
        $latePayment = $this->mockConfirmedPayment($obligationId, $customer->id);
        $service->recordPaymentConfirmation($latePayment);

        // Fetch aggregate report as reporter
        $this->withActorToken($manager);
        $repRes = $this->getJson("$url/collections/$collectionId/aggregate-report")->assertOk();
        $data = $repRes->json('data');

        // Verify aggregate metrics are present
        $this->assertSame(2000, $data['confirmed_gross_minor']);
        $this->assertSame(2000, $data['late_review_gross_minor']);
        $this->assertSame(0, $data['net_support_minor']);
        $this->assertGreaterThan(0, $data['unresolved_liability_count']);
        $this->assertSame('unresolved', $data['settlement_status']);
        $this->assertSame('blocked', $data['payout_status']);

        // Verify privacy: no household names, bank accounts, or PII exposed in report
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIV-HH', $json);
        $this->assertStringNotContainsString('test.local', $json);
        $this->assertStringNotContainsString('ACC-TEST', $json);

        // Attempt archive while unresolved liabilities exist without carry-forward -> rejected
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/archive")->assertStatus(422);

        // Request carry forward
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/carry-forward", [
            'carry_forward_reference' => 'CF-AUDIT-2026-TEST',
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_approval');

        // Manager without review permission cannot approve carry forward
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/carry-forward/approve")->assertForbidden();

        // Reviewer approves carry forward
        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/carry-forward/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        // Archive now succeeds
        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/archive")
            ->assertOk()->assertJsonPath('data.status', 'archived');

        $this->assertSame('archived', DB::connection('community_services')
            ->table('community_services.funeral_collections')->where('id', $collectionId)->value('status'));
    }

    /** @return array{0:object, 1:object, 2:object, 3:string, 4:string} */
    private function setupActiveCollection(): array
    {
        $manager = $this->makeAdminWithPermission('community.funeral.manage', 'community.funeral.report');
        $reviewer = $this->makeAdminWithPermission('community.funeral.review');
        $customer = $this->makeActorWithPermission('CUSTOMER', 'community.funeral.household.read');
        $orgId = (string) Uuid::uuid7();

        $beneficiary = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $orgId,
            'account_type' => 'bank_account', 'account_number' => 'ACC-TEST-REP',
            'account_holder_name' => 'Beneficiary Rep', 'currency_code' => 'BWP',
            'status' => PayoutBeneficiaryStatus::Verified, 'verified_at' => now(), 'verification_reference' => 'REF-REP',
            'created_by' => $manager->id,
        ]);

        $url = '/api/v1/community/funeral';

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $comm = $this->postJson("$url/communities", ['organisation_id' => $orgId, 'display_name' => 'Report Test Comm'])->assertCreated();
        $communityId = $comm->json('data.id');

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/communities/$communityId/staff", ['actor_id' => $reviewer->id, 'role' => 'reviewer'])->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $hh = $this->postJson("$url/communities/$communityId/households", ['private_reference' => 'PRIV-HH-REP'])->assertCreated();
        $householdId = $hh->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/review", ['decision' => 'eligible'])->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $rep = $this->postJson("$url/households/$householdId/representatives", [
            'actor_id' => $customer->id, 'evidence_reference' => 'evidence-rep',
        ])->assertCreated();
        $repId = $rep->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/households/$householdId/representatives/$repId/verify")->assertOk();

        $this->withActorToken($manager, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $coll = $this->postJson("$url/communities/$communityId/collections", [
            'beneficiary_id' => $beneficiary->id, 'evidence_reference' => 'evidence-coll',
        ])->assertCreated();
        $collectionId = $coll->json('data.id');

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/review")->assertOk();

        $this->withActorToken($reviewer, ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $this->postJson("$url/collections/$collectionId/activate")->assertOk();

        $obligationId = (string) DB::connection('community_services')
            ->table('community_services.funeral_obligations')->where('collection_id', $collectionId)->value('id');

        return [$manager, $reviewer, $customer, $collectionId, $obligationId];
    }

    /** @return array<string, int|string> */
    private function mockConfirmedPayment(string $obligationId, string $payerId): array
    {
        $obligation = DB::connection('community_services')->table('community_services.funeral_obligations')->where('id', $obligationId)->first();
        $collection = DB::connection('community_services')->table('community_services.funeral_collections')->where('id', $obligation->collection_id)->first();
        $community = DB::connection('community_services')->table('community_services.funeral_communities')->where('id', $collection->community_id)->first();

        $intentId = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $providerId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();
        $providerCode = 'prov_'.substr($providerId, 0, 8);

        DB::connection('payment')->table('payment.payment_providers')->insert([
            'id' => $providerId, 'code' => $providerCode, 'display_name' => 'Test Provider',
            'adapter_identifier' => 'test', 'adapter_version' => '1.0.0', 'environment' => 'mock',
            'is_enabled' => false, 'is_suspended' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $providerId, 'merchant_account_reference' => 'ACC-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id' => $intentId, 'reference' => 'INT-'.substr($intentId, 0, 8),
            'purpose_type' => 'funeral_contribution', 'purpose_reference' => $obligationId,
            'organisation_id' => $community->organisation_id, 'payer_actor_id' => $payerId,
            'currency_code' => 'BWP', 'amount_minor' => 2000, 'status' => 'succeeded',
            'accounting_status' => 'ledger_posted', 'fulfilment_status' => 'pending',
            'version' => 1, 'idempotency_key' => 'key-'.$intentId, 'correlation_id' => $intentId,
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
            'organisation_id' => (string) $community->organisation_id, 'payer_actor_id' => $payerId,
            'amount_minor' => 2000, 'currency_code' => 'BWP', 'fee_minor' => 0,
            'correlation_id' => $intentId, 'occurred_at' => now()->toIso8601String(),
        ];
    }
}
