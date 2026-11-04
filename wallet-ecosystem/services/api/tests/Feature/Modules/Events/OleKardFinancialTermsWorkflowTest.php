<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Events;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OrganiserRepresentativeVerifier;
use App\Modules\Identity\Domain\ValueObjects\OrganiserRepresentativeEvidence;
use App\Modules\Platform\Application\Services\OleKardFinancialTermsService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\FinancialTermsApprovalEvidenceVerifier;
use App\Modules\Platform\Domain\Contracts\OleKardTermsAcceptanceQuery;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Domain\ValueObjects\FinancialTermsApprovalEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class OleKardFinancialTermsWorkflowTest extends IntegrationTestCase
{
    protected $connectionsToTransact = ['identity', 'platform', 'community_services'];

    public function test_admin_terms_draft_route_is_permission_gated_and_marks_development_input_test_only(): void
    {
        $user = $this->makeAdminWithPermission('platform.financial_terms.draft');
        $response = $this->withActorToken($user)
            ->postJson('/v1/platform/financial-terms', [
                'content' => ['fees' => 'TEST_ONLY: no production value'],
                'effective_from' => now()->addDay()->toIso8601String(),
                'effective_until' => now()->addDays(2)->toIso8601String(),
            ], ['Idempotency-Key' => (string) Uuid::uuid7()]);
        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.test_only', true);
        $this->assertSame(0, DB::connection('identity')->table('identity.role_permissions as rp')
            ->join('identity.permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('identity.roles as r', 'r.id', '=', 'rp.role_id')->where('r.is_system_role', true)
            ->whereIn('p.slug', ['platform.financial_terms.draft', 'platform.financial_terms.approve', 'platform.financial_terms.publish'])
            ->count(), 'The new system permissions must not be auto-granted to existing roles.');
    }

    public function test_approved_published_terms_require_current_representative_acceptance_and_return_immutable_snapshot(): void
    {
        $maker = (string) Uuid::uuid7();
        $checker = (string) Uuid::uuid7();
        $publisher = (string) Uuid::uuid7();
        $representative = (string) Uuid::uuid7();
        $organisation = (string) Uuid::uuid7();
        $event = (string) Uuid::uuid7();

        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturnUsing(static fn (string $actor, string $permission): bool => match ($permission) {
            'platform.financial_terms.draft' => $actor === $maker,
            'platform.financial_terms.approve' => $actor === $checker,
            'platform.financial_terms.publish' => $actor === $publisher,
            default => false,
        });
        $this->app->instance(IdentityModuleContract::class, $identity);

        $verifier = \Mockery::mock(OrganiserRepresentativeVerifier::class);
        $verifier->shouldReceive('verifyForEventTerms')->twice()->with($organisation, $representative, $event)
            ->andReturn(new OrganiserRepresentativeEvidence(
                $organisation, $representative, $event, (string) Uuid::uuid7(), 2,
                'TEST_ONLY:identity-representative-evidence', now()->subMinute()->toDateTimeImmutable(), now()->addHour()->toDateTimeImmutable(),
            ));
        $this->app->instance(OrganiserRepresentativeVerifier::class, $verifier);
        $approvalVerifier = \Mockery::mock(FinancialTermsApprovalEvidenceVerifier::class);
        $approvalVerifier->shouldReceive('verify')->once()->andReturnUsing(static fn (string $reference, string $actorId, int $version, string $hash): FinancialTermsApprovalEvidence => new FinancialTermsApprovalEvidence($reference, $actorId, $version, $hash, now()->toDateTimeImmutable(), 'TEST_ONLY: Finance approval fixture'));
        $this->app->instance(FinancialTermsApprovalEvidenceVerifier::class, $approvalVerifier);
        $audit = \Mockery::mock(AuditServiceInterface::class);
        $audit->shouldReceive('record')->times(4)->with(\Mockery::type(AuditEventData::class));
        $this->app->instance(AuditServiceInterface::class, $audit);

        $service = $this->app->make(OleKardFinancialTermsService::class);
        $content = array_fill_keys([
            'seller_identity', 'fees', 'tax', 'refund_eligibility', 'refund_deadlines', 'fee_refundability',
            'provider_charge_treatment', 'funding_responsibility', 'partial_refunds', 'admitted_ticket_refunds',
            'late_capture', 'in_flight_payments', 'currency_rounding',
        ], 'TEST_ONLY: synthetic value; not approved financial policy');
        $draft = $service->draft($content, now()->subMinute()->toIso8601String(), now()->addDay()->toIso8601String(),
            $maker, 'terms-draft-test-001', (string) Uuid::uuid7());
        $originalHash = (string) $draft['content_hash'];
        $approved = $service->approve((string) $draft['id'], $checker, 'TEST_ONLY: approval evidence fixture', (string) Uuid::uuid7());
        $this->assertSame('approved', $approved['status']);
        $published = $service->publish((string) $draft['id'], $publisher, (string) Uuid::uuid7());
        $this->assertSame('published', $published['status']);

        $accepted = $service->accept((string) $draft['id'], $organisation, $event, $representative,
            'terms-acceptance-test-001', (string) Uuid::uuid7());
        $this->assertSame($originalHash, $accepted['accepted_content_hash']);
        $snapshot = $this->app->make(OleKardTermsAcceptanceQuery::class)->currentAcceptanceSnapshot($organisation, $event);
        $this->assertNotNull($snapshot);
        $this->assertSame($originalHash, $snapshot['document_hash']);
        $this->assertSame($representative, $snapshot['accepted_by_actor_id']);
        $this->assertSame($event, $snapshot['event_id']);
        $this->assertSame($originalHash, DB::connection('platform')->table('platform.olekard_financial_terms_versions')->where('id', $draft['id'])->value('content_hash'));
    }

    public function test_author_cannot_approve_own_terms_and_no_current_acceptance_is_returned(): void
    {
        $actor = (string) Uuid::uuid7();
        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturn(true);
        $this->app->instance(IdentityModuleContract::class, $identity);
        // There is no default active terms version; this read must fail closed without a representative lookup.
        $this->assertNull($this->app->make(OleKardTermsAcceptanceQuery::class)->currentAcceptanceSnapshot((string) Uuid::uuid7(), (string) Uuid::uuid7()));
        $audit = \Mockery::mock(AuditServiceInterface::class);
        $audit->shouldReceive('record')->once()->with(\Mockery::type(AuditEventData::class));
        $this->app->instance(AuditServiceInterface::class, $audit);
        $service = $this->app->make(OleKardFinancialTermsService::class);
        $draft = $service->draft(['draft_clause' => 'TEST_ONLY'], now()->toIso8601String(), null,
            $actor, 'terms-self-approval-test-001', (string) Uuid::uuid7());
        try {
            $service->approve((string) $draft['id'], $actor, 'TEST_ONLY: reference', (string) Uuid::uuid7());
            $this->fail('The terms author must not approve their own version.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_missing_authoritative_approval_evidence_keeps_terms_draft_unapproved(): void
    {
        $maker = (string) Uuid::uuid7();
        $checker = (string) Uuid::uuid7();
        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturn(true);
        $this->app->instance(IdentityModuleContract::class, $identity);
        $audit = \Mockery::mock(AuditServiceInterface::class);
        $audit->shouldReceive('record')->once()->with(\Mockery::type(AuditEventData::class));
        $this->app->instance(AuditServiceInterface::class, $audit);

        $service = $this->app->make(OleKardFinancialTermsService::class);
        $content = array_fill_keys([
            'seller_identity', 'fees', 'tax', 'refund_eligibility', 'refund_deadlines', 'fee_refundability',
            'provider_charge_treatment', 'funding_responsibility', 'partial_refunds', 'admitted_ticket_refunds',
            'late_capture', 'in_flight_payments', 'currency_rounding',
        ], 'TEST_ONLY: synthetic value; not approved financial policy');
        $draft = $service->draft($content, now()->subMinute()->toIso8601String(), now()->addDay()->toIso8601String(),
            $maker, 'terms-no-evidence-test-001', (string) Uuid::uuid7());
        try {
            $service->approve((string) $draft['id'], $checker, 'unverified-reference', (string) Uuid::uuid7());
            $this->fail('An approval reference must not substitute for authoritative approval evidence.');
        } catch (ValidationException) {
            $row = DB::connection('platform')->table('platform.olekard_financial_terms_versions')->where('id', $draft['id'])->first();
            $this->assertSame('draft', $row->status);
            $this->assertNull($row->approved_by_actor_id);
            $this->assertNull($row->approval_content_hash);
        }
    }

    public function test_overlapping_effective_terms_periods_cannot_both_be_published(): void
    {
        $actors = array_map(static fn (): string => (string) Uuid::uuid7(), range(1, 5));
        [$makerOne, $checkerOne, $makerTwo, $checkerTwo, $publisher] = $actors;
        $identity = \Mockery::mock(IdentityModuleContract::class);
        $identity->shouldReceive('hasPermission')->andReturn(true);
        $this->app->instance(IdentityModuleContract::class, $identity);
        $approvalVerifier = \Mockery::mock(FinancialTermsApprovalEvidenceVerifier::class);
        $approvalVerifier->shouldReceive('verify')->twice()->andReturnUsing(static fn (string $reference, string $actorId, int $version, string $hash): FinancialTermsApprovalEvidence => new FinancialTermsApprovalEvidence($reference, $actorId, $version, $hash, now()->toDateTimeImmutable(), 'TEST_ONLY: overlap approval fixture'));
        $this->app->instance(FinancialTermsApprovalEvidenceVerifier::class, $approvalVerifier);
        $audit = \Mockery::mock(AuditServiceInterface::class);
        $audit->shouldReceive('record')->times(5)->with(\Mockery::type(AuditEventData::class));
        $this->app->instance(AuditServiceInterface::class, $audit);

        $content = array_fill_keys([
            'seller_identity', 'fees', 'tax', 'refund_eligibility', 'refund_deadlines', 'fee_refundability',
            'provider_charge_treatment', 'funding_responsibility', 'partial_refunds', 'admitted_ticket_refunds',
            'late_capture', 'in_flight_payments', 'currency_rounding',
        ], 'TEST_ONLY: synthetic value; not approved financial policy');
        $service = $this->app->make(OleKardFinancialTermsService::class);
        $start = now()->addMinute()->toIso8601String();
        $end = now()->addDays(3)->toIso8601String();
        $first = $service->draft($content, $start, $end, $makerOne, 'terms-overlap-draft-001', (string) Uuid::uuid7());
        $second = $service->draft($content, $start, $end, $makerTwo, 'terms-overlap-draft-002', (string) Uuid::uuid7());
        $service->approve((string) $first['id'], $checkerOne, 'TEST_ONLY: approval-one', (string) Uuid::uuid7());
        $service->approve((string) $second['id'], $checkerTwo, 'TEST_ONLY: approval-two', (string) Uuid::uuid7());
        $service->publish((string) $first['id'], $publisher, (string) Uuid::uuid7());

        try {
            $service->publish((string) $second['id'], $publisher, (string) Uuid::uuid7());
            $this->fail('Overlapping terms must not both publish.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(1, DB::connection('platform')->table('platform.olekard_financial_terms_versions')->where('status', 'published')->count());
        }
    }

    public function test_events_terms_guard_serializes_order_writes_against_publication_on_another_session(): void
    {
        $config = config('database.connections.platform');
        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'], $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        try {
            $query = $this->app->make(OleKardTermsAcceptanceQuery::class);
            $query->withPublicationGuard(function () use ($pdo): void {
                $result = $pdo->query("SELECT pg_try_advisory_xact_lock(hashtextextended('olekard-financial-terms-version', 0))")->fetchColumn();
                $this->assertContains($result, [false, 'f', 0, '0'], 'A second PostgreSQL session must wait while an Events write holds the terms guard.');
            });
            $released = $pdo->query("SELECT pg_try_advisory_xact_lock(hashtextextended('olekard-financial-terms-version', 0))")->fetchColumn();
            $this->assertContains($released, [true, 't', 1, '1'], 'The Platform session lock must be released after the Events write completes.');
        } finally {
            $pdo = null;
        }
    }

    public function test_events_terms_guard_releases_platform_lock_after_operation_failure(): void
    {
        $config = config('database.connections.platform');
        $pdo = new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'], $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        try {
            try {
                $this->app->make(OleKardTermsAcceptanceQuery::class)->withPublicationGuard(
                    static fn (): never => throw new \RuntimeException('injected guarded-write failure'),
                );
                $this->fail('The injected failure must escape the guarded operation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('injected guarded-write failure', $exception->getMessage());
            }

            $acquired = $pdo->query("SELECT pg_try_advisory_xact_lock(hashtextextended('olekard-financial-terms-version', 0))")->fetchColumn();
            $this->assertContains($acquired, [true, 't', 1, '1'], 'The publication lock must be released by finally after an Events operation throws.');
        } finally {
            $pdo = null;
        }
    }
}
