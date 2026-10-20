<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Notification\Application\Jobs\DispatchNotificationJob;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Application\Jobs\DeliverInvestigationEventsJob;
use App\Modules\Risk\Application\Services\AccountHoldService;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskEvent;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        // Provision only missing dependency tables inside this test's rollback transactions.
        if (! Schema::connection('platform')->hasTable('security.vulnerability_findings')) {
            (require app_path('Modules/Security/Infrastructure/Migrations/2026_10_02_026000_create_security_vulnerability_findings_table.php'))->up();
        }
        if (! Schema::connection('notification')->hasTable('notification.notification_templates')) {
            (require app_path('Modules/Notification/Infrastructure/Migrations/2026_10_01_017000_create_notification_schema.php'))->up();
            (require app_path('Modules/Notification/Infrastructure/Migrations/2026_10_01_017300_create_notification_templates_table.php'))->up();
        }
        if (! Schema::connection('notification')->hasTable('notification.notifications')) {
            (require app_path('Modules/Notification/Infrastructure/Migrations/2026_10_01_017100_create_notifications_table.php'))->up();
        }

        config(['cache.default' => 'array']);
        $manager = $this->app->make('redis');
        $reflection = new \ReflectionProperty($manager, 'connections');
        $reflection->setAccessible(true);
        $connections = $reflection->getValue($manager);
        $connections['identity'] = new FakeRedisConnection;
        $reflection->setValue($manager, $connections);

        if (! Schema::connection('risk')->hasTable('risk.alert_notes')) {
            (require app_path('Modules/Risk/Infrastructure/Migrations/2026_10_14_018000_add_admin_investigation_tables.php'))->up();
        }
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
    }

    private function alert(array $values = []): RiskAlert
    {
        return RiskAlert::create(array_merge([
            'id' => (string) Uuid::uuid7(), 'alert_type' => 'threshold_breach', 'status' => 'open',
            'severity' => 'high', 'subject_type' => 'customer', 'subject_id' => (string) Uuid::uuid7(),
            'title' => 'Suspicious transfer', 'description' => 'Threshold trigger', 'sla_due_at' => now()->addDay(),
            'created_by' => (string) Uuid::uuid7(), 'updated_by' => (string) Uuid::uuid7(), 'version' => 1,
        ], $values));
    }

    private function admin(array $extra = []): string
    {
        $actor = $this->createAuthenticatedAdmin(array_merge(['security.alerts.read', 'risk.manage_alerts'], $extra));
        $this->actingAs($actor);

        return $actor->id;
    }

    public function test_queue_requires_permission_and_filters_groups(): void
    {
        $a = $this->alert();
        $this->alert(['severity' => 'low', 'status' => 'resolved_false_positive']);
        $this->admin();
        $this->getJson('/v1/admin/security/alerts?severity=high&status=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/v1/admin/security/alerts?status=resolved')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->createAuthenticatedAdmin([]))->getJson('/v1/admin/security/alerts')->assertForbidden();
    }

    public function test_investigation_notes_links_assignment_and_stale_conflicts(): void
    {
        $a = $this->alert();
        $b = $this->alert();
        $actor = $this->admin();
        $base = "/v1/admin/security/alerts/{$a->id}";
        $this->postJson("$base/assign", ['version' => 1])->assertOk()->assertJsonPath('data.assigned_to', $actor);
        $this->postJson("$base/notes", ['version' => 1, 'note' => 'stale'])->assertConflict();
        $this->postJson("$base/notes", ['version' => 2, 'note' => 'Investigated device'])->assertOk();
        $this->postJson("$base/links", ['version' => 3, 'related_alert_id' => $a->id])->assertUnprocessable();
        $this->postJson("$base/links", ['version' => 3, 'related_alert_id' => $b->id])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('data.notes.0.note', 'Investigated device')->assertJsonPath('data.related_alerts.0.id', $b->id)
            ->assertJsonStructure(['data' => ['trigger', 'affected_transactions', 'entity_history', 'risk_timeline']]);
        $this->postJson("$base/escalate", ['version' => 4])->assertOk()->assertJsonPath('data.status', 'escalated');
        $this->postJson("$base/resolve", ['version' => 5, 'outcome' => 'confirmed_fraud', 'rationale' => 'Confirmed transaction fraud'])->assertOk()->assertJsonPath('data.status', 'resolved_true_positive');
        $this->postJson("$base/notes", ['version' => 6, 'note' => 'after close'])->assertUnprocessable();
    }

    public function test_false_positive_and_invalid_outcome(): void
    {
        $a = $this->alert();
        $this->admin();
        $base = "/v1/admin/security/alerts/{$a->id}";
        $this->postJson("$base/resolve", ['version' => 1, 'outcome' => 'invalid', 'rationale' => 'Investigated transaction'])->assertUnprocessable();
        $this->postJson("$base/resolve", ['version' => 1, 'outcome' => 'false_positive', 'rationale' => 'Legitimate transaction verified'])->assertOk()->assertJsonPath('data.status', 'resolved_false_positive');
    }

    public function test_freeze_needs_independent_checker_and_is_replay_safe(): void
    {
        $a = $this->alert();
        $maker = $this->admin(['risk.freeze_account']);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $request = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transaction confirmed'])->assertCreated()->json('data.id');
        $this->assertDatabaseMissing('risk.account_holds', ['subject_id' => $a->subject_id], 'risk');
        $this->postJson("$base/freeze-requests/$request/approve")->assertForbidden();
        $checker = $this->admin(['risk.freeze_account']);
        $this->postJson("$base/freeze-requests/$request/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("$base/freeze-requests/$request/approve")->assertOk();
        $this->assertEquals(1, DB::connection('risk')->table('risk.account_holds')->where('subject_id', $a->subject_id)->count());
        $hold = DB::connection('risk')->table('risk.account_holds')->where('subject_id', $a->subject_id)->first();
        $this->assertEquals($maker, $hold->placed_by);
        $this->assertEquals(1, DB::connection('risk')->table('risk.investigation_outbox')->where('event_name', 'ACCOUNT_FROZEN_AML')->count());
        Cache::forget("hold_active:customer:{$a->subject_id}");
    }

    public function test_read_permission_cannot_mutate_or_freeze(): void
    {
        $a = $this->alert();
        $this->actingAs($this->createAuthenticatedAdmin(['security.alerts.read']));
        $base = "/v1/admin/security/alerts/{$a->id}";
        $this->postJson("$base/notes", ['version' => 1, 'note' => 'forbidden'])->assertForbidden();
        $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'forbidden freeze'])->assertForbidden();
        $this->getJson('/v1/admin/security/alerts/'.Uuid::uuid7())->assertNotFound();
    }

    public function test_detail_uses_real_linked_transactions_and_score_history(): void
    {
        $a = $this->alert();
        $this->admin();
        $evaluation = RiskEvaluation::create([
            'id' => (string) Uuid::uuid7(), 'idempotency_key' => (string) Uuid::uuid7(), 'transaction_reference' => 'TX-EVIDENCE',
            'transaction_type' => 'transfer', 'amount' => '125.0000', 'currency' => 'BWP', 'customer_id' => $a->subject_id,
            'overall_score' => 87, 'risk_level' => 'high', 'decision' => 'review', 'evaluation_ms' => 1, 'evaluated_at' => now(), 'rules_applied' => [],
        ]);
        DB::connection('risk')->table('risk.risk_evaluations')->insert(array_merge($evaluation->getAttributes(), ['id' => (string) Uuid::uuid7(), 'idempotency_key' => (string) Uuid::uuid7(), 'overall_score' => 65, 'evaluated_at' => now()->subHour()]));
        $event = RiskEvent::create([
            'id' => (string) Uuid::uuid7(), 'event_type' => 'threshold_breach', 'severity' => 'high', 'subject_type' => 'customer',
            'subject_id' => $a->subject_id, 'transaction_reference' => 'TX-EVIDENCE', 'evaluation_id' => $evaluation->id,
            'correlation_id' => (string) Uuid::uuid7(), 'context' => ['trigger' => 'velocity'], 'detected_at' => now(), 'created_at' => now(),
        ]);
        DB::connection('risk')->table('risk.risk_alert_events')->insert(['alert_id' => $a->id, 'event_id' => $event->id, 'linked_at' => now()]);
        $this->getJson("/v1/admin/security/alerts/{$a->id}")->assertOk()
            ->assertJsonPath('data.affected_transactions.0.reference', 'TX-EVIDENCE')
            ->assertJsonPath('data.entity_history.0.event_type', 'threshold_breach')
            ->assertJsonPath('data.risk_timeline.0.score', 65)->assertJsonPath('data.risk_timeline.1.score', 87);
    }

    public function test_findings_additive_states_cve_and_server_permissions(): void
    {
        (require app_path('Modules/Security/Infrastructure/Migrations/2026_10_14_018001_extend_finding_states.php'))->up();
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('security.vulnerability_findings')->insert([
            'id' => $id, 'scanner' => 'test', 'finding_ref' => 'CVE-2026-12345', 'severity' => 'critical', 'component' => 'dependency',
            'title' => 'Dependency vulnerability', 'status' => 'open', 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->createAuthenticatedAdmin(['security.findings.read', 'security.findings.write']));
        $this->getJson('/v1/admin/security/findings?severity=critical')->assertOk()->assertJsonPath('data.0.cve_id', 'CVE-2026-12345');
        foreach (['acknowledged', 'in_remediation', 'resolved'] as $status) {
            $this->patchJson("/v1/security/findings/$id/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->actingAs($this->createAuthenticatedAdmin(['security.findings.read']));
        $this->patchJson("/v1/security/findings/$id/status", ['status' => 'acknowledged'])->assertForbidden();
    }

    public function test_freeze_event_delivers_configured_notification_once(): void
    {
        Queue::fake();
        $recipient = $this->createAuthenticatedUser();
        $a = $this->alert(['subject_id' => $recipient->id]);
        $this->admin(['risk.freeze_account']);
        NotificationTemplateModel::create([
            'id' => (string) Uuid::uuid7(), 'key' => 'security.account_frozen_aml', 'category' => 'security',
            'subject_template' => 'Account under investigation', 'body_template' => 'Reference {{support_ref}} at {{occurred_at}}',
            'supported_channels' => ['email'], 'variables' => ['support_ref', 'occurred_at'], 'active' => true,
        ]);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $r = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transfer requires review'])->assertCreated()->json('data.id');
        $this->admin(['risk.freeze_account']);
        $this->postJson("$base/freeze-requests/$r/approve")->assertOk();
        $job = new DeliverInvestigationEventsJob;
        $job->handle();
        $job->handle();
        $this->assertEquals(1, DB::connection('notification')->table('notification.notifications')->where('notification_type', 'security.account_frozen_aml')->where('recipient_id', $recipient->id)->count());
        Queue::assertPushed(DispatchNotificationJob::class, 1);
        $this->assertEquals(0, DB::connection('risk')->table('risk.investigation_outbox')->whereNull('delivered_at')->count());
        Cache::forget("hold_active:customer:{$a->subject_id}");
    }

    public function test_outbox_retries_listener_failure_and_skips_unconfigured_notification(): void
    {
        Queue::fake();
        $a = $this->alert();
        $this->admin(['risk.freeze_account']);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $r = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transfer requires review'])->assertCreated()->json('data.id');
        $this->admin(['risk.freeze_account']);
        $this->postJson("$base/freeze-requests/$r/approve")->assertOk();
        $attempts = 0;
        Event::listen('ACCOUNT_FROZEN_AML', function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new \RuntimeException('transient receiver failure');
            }
        });
        $job = new DeliverInvestigationEventsJob;
        try {
            $job->handle();
            $this->fail('Expected receiver failure');
        } catch (\RuntimeException $e) {
            $this->assertEquals('transient receiver failure', $e->getMessage());
        }
        $this->assertEquals(1, DB::connection('risk')->table('risk.investigation_outbox')->whereNull('delivered_at')->count());
        $job->handle();
        $this->assertEquals(0, DB::connection('risk')->table('risk.investigation_outbox')->whereNull('delivered_at')->count());
        Queue::assertNothingPushed();
        Cache::forget("hold_active:customer:{$a->subject_id}");
    }

    public function test_forged_identity_and_duplicate_request_cannot_bypass_approval(): void
    {
        $a = $this->alert();
        $maker = $this->admin(['risk.freeze_account']);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $r = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transfer requires review', 'subject_id' => (string) Uuid::uuid7(), 'checker_id' => (string) Uuid::uuid7()])->assertCreated()->json('data.id');
        $this->postJson("$base/freeze-requests", ['version' => 2, 'reason' => 'Duplicate transfer review'])->assertConflict();
        $this->postJson("$base/freeze-requests/$r/approve", ['checker_id' => (string) Uuid::uuid7()])->assertForbidden();
        $this->actingAs($this->createAuthenticatedAdmin(['security.alerts.read']));
        $this->postJson("$base/freeze-requests/$r/approve")->assertForbidden();
        $this->assertDatabaseMissing('risk.account_holds', ['subject_id' => $a->subject_id], 'risk');
    }

    public function test_wallet_alert_freezes_verified_owner_not_supplied_subject(): void
    {
        $recipient = $this->createAuthenticatedUser();
        $wallet = (string) Uuid::uuid7();
        $contract = $this->createMock(WalletModuleContract::class);
        $contract->method('ownerActorId')->with($wallet)->willReturn($recipient->id);
        $this->instance(WalletModuleContract::class, $contract);
        $a = $this->alert(['subject_type' => 'wallet', 'subject_id' => $wallet]);
        $this->admin(['risk.freeze_account']);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $r = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Wallet transfer needs investigation'])->assertCreated()->json('data.id');
        $this->admin(['risk.freeze_account']);
        $this->postJson("$base/freeze-requests/$r/approve")->assertOk();
        $this->assertDatabaseHas('risk.account_holds', ['subject_id' => $recipient->id, 'wallet_id' => $wallet, 'subject_type' => 'customer'], 'risk');
        $this->assertDatabaseHas('risk.investigation_actions', ['alert_id' => $a->id, 'action' => 'account_frozen_aml'], 'risk');
        Cache::forget("hold_active:customer:{$recipient->id}");
    }

    public function test_real_audit_records_authenticated_actor(): void
    {
        $this->instance(AuditServiceInterface::class, new AuditService);
        $a = $this->alert();
        $actor = $this->admin();
        $this->postJson("/v1/admin/security/alerts/{$a->id}/notes", ['version' => 1, 'note' => 'Evidence inspected'])->assertOk();
        $this->assertDatabaseHas('platform.audit_events', ['action' => 'risk.investigation.notes', 'actor_id' => $actor, 'resource_id' => $a->id], 'platform');
        $this->assertDatabaseHas('risk.investigation_actions', ['alert_id' => $a->id, 'actor_id' => $actor, 'action' => 'notes'], 'risk');
    }

    public function test_failed_freeze_leaves_request_pending_without_hold_event_or_cache(): void
    {
        $a = $this->alert();
        $this->admin(['risk.freeze_account']);
        $base = "/v1/admin/security/alerts/{$a->id}";
        $r = $this->postJson("$base/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transfer requires review'])->assertCreated()->json('data.id');
        $this->admin(['risk.freeze_account']);
        $audit = $this->createMock(AuditServiceInterface::class);
        $audit->method('record')->willThrowException(new \RuntimeException('audit unavailable'));
        $this->instance(AuditServiceInterface::class, $audit);
        $this->app->forgetInstance(AccountHoldService::class);
        $this->postJson("$base/freeze-requests/$r/approve")->assertStatus(500);
        $this->assertDatabaseMissing('risk.account_holds', ['subject_id' => $a->subject_id], 'risk');
        $this->assertDatabaseHas('risk.alert_freeze_requests', ['id' => $r, 'status' => 'pending'], 'risk');
        $this->assertEquals(0, DB::connection('risk')->table('risk.investigation_outbox')->count());
        $this->assertFalse(Cache::has("hold_active:customer:{$a->subject_id}"));
    }

    public function test_same_account_from_two_alerts_cannot_receive_duplicate_holds(): void
    {
        $a = $this->alert();
        $b = $this->alert(['subject_id' => $a->subject_id]);
        $this->admin(['risk.freeze_account']);
        $base = '/v1/admin/security/alerts';
        $r1 = $this->postJson("$base/{$a->id}/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transaction one'])->assertCreated()->json('data.id');
        $r2 = $this->postJson("$base/{$b->id}/freeze-requests", ['version' => 1, 'reason' => 'Suspicious transaction two'])->assertCreated()->json('data.id');
        $this->admin(['risk.freeze_account']);
        $this->postJson("$base/{$a->id}/freeze-requests/$r1/approve")->assertOk();
        $this->postJson("$base/{$b->id}/freeze-requests/$r2/approve")->assertConflict();
        $this->assertEquals(1, DB::connection('risk')->table('risk.account_holds')->where('subject_id', $a->subject_id)->count());
    }

    public function test_missing_authentication_is_rejected(): void
    {
        $this->getJson('/v1/admin/security/alerts')->assertUnauthorized();
    }
}
