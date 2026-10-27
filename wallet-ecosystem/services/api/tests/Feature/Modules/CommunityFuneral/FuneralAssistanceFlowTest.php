<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CommunityFuneral;

use App\Modules\CommunityFuneral\Application\FuneralAssistanceService;
use App\Modules\CommunityFuneral\Application\FuneralObligationResolver;
use App\Modules\CommunityFuneral\Application\Jobs\DispatchFuneralPrivateNotice;
use App\Modules\CommunityFuneral\Domain\Contracts\OrganisationMembershipVerifier;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use App\Modules\Notification\Application\Services\NotificationTemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class FuneralAssistanceFlowTest extends IntegrationTestCase
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

    public function test_p18_beneficiary_schema_and_permission_contract_exist(): void
    {
        $this->assertTrue(Schema::connection('payment')->hasTable('payment.payout_beneficiaries'));
        $columns = Schema::connection('payment')->getColumnListing('payment.payout_beneficiaries');
        foreach (['id', 'organisation_id', 'account_number', 'status', 'verification_reference'] as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertTrue(DB::connection('identity')->table('identity.permissions')
            ->where('slug', 'payment.payout.manage_beneficiaries')->exists());
    }

    public function test_activation_freezes_one_bwp20_obligation_and_restricts_its_resolver(): void
    {
        [$service, $context] = $this->approvedCollection();
        $outsider = $this->actor('CUSTOMER');
        $service->addRepresentative($context['household_id'], $outsider->id, 'restricted-test-only', $context['manager_id']);
        $first = $service->activateCollection($context['collection_id'], $context['reviewer_id']);

        $this->assertSame(1, $first['eligible_count']);
        $this->assertSame(0, $first['exempt_count']);
        $this->assertSame(1, $first['obligation_count']);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.funeral_eligibility_snapshots')->where('collection_id', $context['collection_id'])->count());
        $obligation = $this->obligation($context['collection_id']);
        $this->assertSame(2000, (int) $obligation->amount_minor);
        $this->assertSame('BWP', $obligation->currency_code);
        $audit = DB::connection('community_services')->table('community_services.funeral_audit_events')
            ->where('resource_id', $context['collection_id'])->where('action', 'collection.activated')->first();
        $this->assertNotNull($audit);
        $this->assertSame($context['reviewer_id'], $audit->actor_id);
        $platformAudit = DB::connection('platform')->table('platform.audit_events')
            ->where('correlation_id', $audit->id)->first();
        $this->assertNotNull($platformAudit);
        $this->assertSame('community_funeral.collection_activated', $platformAudit->action);
        $notices = DB::connection('community_services')->table('community_services.funeral_private_notices')->get();
        $this->assertCount(1, $notices);
        $this->assertSame($context['customer_id'], $notices[0]->recipient_actor_id);
        $this->assertSame('delivered', $notices[0]->status);
        $this->assertSame([], $service->obligationsForActor($outsider->id));
        $inbox = DB::connection('notification')->table('notification.notifications')
            ->where('source_event_id', $notices[0]->id)->first();
        $this->assertNotNull($inbox);
        $this->assertSame($context['customer_id'], $inbox->recipient_id);
        $this->assertSame('["in_app"]', $inbox->rule_channels);
        $this->assertSame(0, DB::connection('notification')->table('notification.notification_deliveries')
            ->where('notification_id', $inbox->id)->count());
        $this->assertSame(1, DB::connection('notification')->table('notification.notifications')
            ->where('source_event_id', $notices[0]->id)->count());
        DB::connection('community_services')->table('community_services.funeral_private_notices')
            ->where('id', $notices[0]->id)->update(['status' => 'pending']);
        $this->artisan('funeral:notices-retry', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('delivered', DB::connection('community_services')
            ->table('community_services.funeral_private_notices')->where('id', $notices[0]->id)->value('status'));

        $resolved = app(FuneralObligationResolver::class)->resolve($obligation->id, $context['customer_id'], (string) Uuid::uuid7());
        $this->assertSame(2000, $resolved->amountMinor);
        $this->assertSame($context['organisation_id'], $resolved->organisationId);
        $this->expectException(\App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException::class);
        app(FuneralObligationResolver::class)->resolve($obligation->id, $context['reviewer_id'], (string) Uuid::uuid7());
    }

    public function test_beneficiary_edit_invalidates_review_and_prevents_activation(): void
    {
        [$service, $context] = $this->approvedCollection();
        $service->changeBeneficiary($context['collection_id'], (string) Uuid::uuid7(), $context['manager_id']);
        $collection = DB::connection('community_services')->table('community_services.funeral_collections')->where('id', $context['collection_id'])->first();
        $this->assertSame('under_review', $collection->status);
        $this->assertNull($collection->reviewed_by);

        $this->expectException(ValidationException::class);
        $service->activateCollection($context['collection_id'], $context['reviewer_id']);
    }

    public function test_duplicate_confirmation_fulfils_once_and_extra_capture_becomes_open_liability(): void
    {
        [$service, $context] = $this->approvedCollection();
        $service->activateCollection($context['collection_id'], $context['reviewer_id']);
        $obligation = $this->obligation($context['collection_id']);

        $first = $this->confirmedPayment($obligation->id, $context['organisation_id'], $context['customer_id']);
        $service->recordPaymentConfirmation($first);
        $service->recordPaymentConfirmation($first);
        $this->assertSame(1, DB::connection('community_services')->table('community_services.funeral_payment_receipts')->where('obligation_id', $obligation->id)->count());
        $this->assertSame('fulfilled', $this->obligation($context['collection_id'])->fulfilment_status);

        $second = $this->confirmedPayment($obligation->id, $context['organisation_id'], $context['customer_id']);
        $service->recordPaymentConfirmation($second);
        $this->assertSame(2, DB::connection('community_services')->table('community_services.funeral_payment_receipts')->where('obligation_id', $obligation->id)->count());
        $this->assertSame(1, DB::connection('community_services')->table('community_services.funeral_late_reviews')->where('obligation_id', $obligation->id)->count());
        $this->assertSame('fulfilled', $this->obligation($context['collection_id'])->fulfilment_status);
    }

    public function test_after_close_capture_stays_under_review_and_archive_needs_independent_carry_forward(): void
    {
        [$service, $context] = $this->approvedCollection();
        $service->activateCollection($context['collection_id'], $context['reviewer_id']);
        $obligation = $this->obligation($context['collection_id']);
        $service->closeCollection($context['collection_id'], $context['manager_id']);
        $event = $this->confirmedPayment($obligation->id, $context['organisation_id'], $context['customer_id']);
        $service->recordPaymentConfirmation($event);

        $this->assertSame('late_review', $this->obligation($context['collection_id'])->status);
        $this->assertSame('held_for_review', $this->obligation($context['collection_id'])->fulfilment_status);
        $report = $service->aggregateReport($context['collection_id'], $context['manager_id']);
        $this->assertSame(2000, $report['confirmed_gross_minor']);
        $this->assertSame(2000, $report['late_review_gross_minor']);
        $this->assertSame(0, $report['net_support_minor']);
        $this->assertGreaterThan(0, $report['unresolved_liability_count']);

        try {
            $service->archiveCollection($context['collection_id'], $context['manager_id']);
            $this->fail('Archival must fail while late liability is unresolved.');
        } catch (ValidationException) {
            // Expected: no carry-forward approval.
        }
        $service->requestCarryForward($context['collection_id'], 'FINANCE-REVIEW-TEST', $context['manager_id']);
        try {
            $service->approveCarryForward($context['collection_id'], $context['manager_id']);
            $this->fail('Maker must not approve their own carry-forward.');
        } catch (ValidationException) {
            // Expected: maker/checker separation.
        }
        $service->approveCarryForward($context['collection_id'], $context['reviewer_id']);
        $service->archiveCollection($context['collection_id'], $context['manager_id']);
        $this->assertSame('archived', DB::connection('community_services')->table('community_services.funeral_collections')->where('id', $context['collection_id'])->value('status'));
    }

    public function test_unaccounted_or_mismatched_payment_event_cannot_fulfil(): void
    {
        [$service, $context] = $this->approvedCollection();
        $service->activateCollection($context['collection_id'], $context['reviewer_id']);
        $obligation = $this->obligation($context['collection_id']);
        $event = $this->confirmedPayment($obligation->id, $context['organisation_id'], $context['customer_id']);
        $event['organisation_id'] = (string) Uuid::uuid7();

        try {
            $service->recordPaymentConfirmation($event);
            $this->fail('Mismatched organisation should be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::connection('community_services')->table('community_services.funeral_payment_receipts')->where('obligation_id', $obligation->id)->count());
            $this->assertSame('pending', $this->obligation($context['collection_id'])->fulfilment_status);
        }
    }

    /** @return array{0:FuneralAssistanceService,1:array<string,string>} */
    private function approvedCollection(): array
    {
        $manager = $this->actor('ADMIN');
        $reviewer = $this->actor('ADMIN');
        $customer = $this->actor('CUSTOMER');
        $organisationId = (string) Uuid::uuid7();
        $beneficiary = PayoutBeneficiaryModel::create([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => $organisationId,
            'account_type' => 'bank_account', 'account_number' => 'TEST-ONLY-ENCRYPTED',
            'account_holder_name' => 'Private Test Beneficiary', 'currency_code' => 'BWP',
            'status' => 'verified', 'verified_at' => now(), 'verification_reference' => 'TEST-VERIFY',
            'created_by' => $manager->id,
        ]);
        $service = app(FuneralAssistanceService::class);
        $community = $service->createCommunity($organisationId, 'Test Community '.substr((string) Uuid::uuid7(), -8), $manager->id);
        $service->addCommunityStaff($community['id'], $reviewer->id, 'reviewer', $manager->id);
        $household = $service->createHousehold($community['id'], 'HOUSEHOLD-'.substr((string) Uuid::uuid7(), -8), $manager->id);
        $service->reviewHousehold($household['id'], 'eligible', null, $reviewer->id);
        $rep = $service->addRepresentative($household['id'], $customer->id, 'restricted-evidence-test-only', $manager->id);
        $service->verifyRepresentative($household['id'], $rep['id'], $reviewer->id);
        $collection = $service->createCollection($community['id'], $beneficiary->id, 'restricted-request-test-only', $manager->id);
        $service->approveCollection($collection['id'], $reviewer->id);

        return [$service, ['collection_id' => $collection['id'], 'community_id' => $community['id'],
            'household_id' => $household['id'],
            'organisation_id' => $organisationId, 'manager_id' => $manager->id,
            'reviewer_id' => $reviewer->id, 'customer_id' => $customer->id]];
    }

    private function obligation(string $collectionId): object
    {
        return DB::connection('community_services')->table('community_services.funeral_obligations')->where('collection_id', $collectionId)->first();
    }

    private function actor(string $type): object
    {
        $id = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $id, 'email' => 'funeral-'.str_replace('-', '', $id).'@test.local',
            'mobile_number' => '+9'.(string) random_int(100000000, 999999999),
            'status' => 'active', 'actor_type' => $type, 'default_role_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id, 'actor_type' => $type];
    }

    /** @return array<string, int|string> */
    private function confirmedPayment(string $obligationId, string $organisationId, string $payerId): array
    {
        $intentId = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();
        $providerId = (string) Uuid::uuid7();
        $accountId = (string) Uuid::uuid7();
        $providerCode = 'funeral_test_'.str_replace('-', '', $providerId);
        DB::connection('payment')->table('payment.payment_providers')->insert([
            'id' => $providerId, 'code' => $providerCode, 'display_name' => 'P24 test only',
            'adapter_identifier' => 'test_only', 'adapter_version' => '1.0.0',
            'environment' => 'mock', 'is_enabled' => false, 'is_suspended' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $providerId,
            'merchant_account_reference' => 'P24-TEST-ONLY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id' => $intentId, 'reference' => 'FUNERAL-TEST-'.substr($intentId, -8),
            'purpose_type' => 'funeral_contribution', 'purpose_reference' => $obligationId,
            'organisation_id' => $organisationId, 'payer_actor_id' => $payerId,
            'currency_code' => 'BWP', 'amount_minor' => 2000, 'status' => 'succeeded',
            'accounting_status' => 'ledger_posted', 'fulfilment_status' => 'pending',
            'version' => 1, 'idempotency_key' => 'intent-'.$intentId,
            'correlation_id' => $correlationId, 'metadata' => '{}',
            'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id' => $attemptId, 'payment_intent_id' => $intentId,
            'provider_code' => $providerCode, 'provider_account_id' => $accountId,
            'status' => 'succeeded', 'idempotency_key' => 'attempt-'.$intentId,
            'operation_id' => (string) Uuid::uuid7(), 'fee_minor' => 0,
            'initiated_at' => now(),
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['purpose_type' => 'funeral_contribution', 'purpose_reference' => $obligationId,
            'payment_intent_id' => $intentId, 'payment_attempt_id' => $attemptId,
            'organisation_id' => $organisationId, 'payer_actor_id' => $payerId,
            'amount_minor' => 2000, 'currency_code' => 'BWP', 'fee_minor' => 0,
            'correlation_id' => $correlationId, 'occurred_at' => now()->toIso8601String()];
    }
}
