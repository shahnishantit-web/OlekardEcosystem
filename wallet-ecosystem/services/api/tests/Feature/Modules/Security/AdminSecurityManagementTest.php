<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Jobs\ConfigurationActivationJob;
use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationValueModel;
use App\Modules\Security\Application\Services\VulnerabilityFindingService;
use App\Modules\Security\Domain\Enums\VulnerabilityFindingStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminSecurityManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $m = app('redis');
        $p = new \ReflectionProperty($m, 'connections');
        $c = $p->getValue($m);
        $c['identity'] = new FakeRedisConnection;
        $p->setValue($m, $c);
        Queue::fake();
    }

    private function admin(array $permissions = ['security.config.read', 'security.config.write', 'configuration.approve', 'security.findings.read', 'security.findings.write', 'security.events.read', 'security.events.export']): string
    {
        $a = $this->createAuthenticatedAdmin($permissions);
        $this->actingAs($a);

        return $a->id;
    }

    private function finding(): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('security.vulnerability_findings')->insert(['id' => $id, 'scanner' => 'test', 'finding_ref' => 'CVE-2026-12345', 'severity' => 'high', 'component' => 'api-auth', 'title' => 'Signature regression', 'status' => 'open', 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_policy_proposal_has_no_effect_until_distinct_checker_approves(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $path = '/v1/admin/platform/security-config';
        $this->getJson($path)->assertOk()->assertJsonStructure(['data' => ['settings' => [['key', 'value', 'last_modified_by']]]]);
        $id = $this->withHeader('Idempotency-Key', $key)->patchJson($path, ['key' => 'auth.password.min_length', 'value' => 14])->assertStatus(202)->json('data.id');
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("$path/$id/approve")->assertStatus(422);
        $this->admin();
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("$path/$id/approve")->assertOk();
        $rows = $this->getJson($path)->assertOk()->json('data.settings');
        $this->assertSame(14, collect($rows)->firstWhere('key', 'auth.password.min_length')['value']);
    }

    public function test_finding_detail_lifecycle_is_audited_and_versioned(): void
    {
        $this->admin();
        $id = $this->finding();
        $path = "/v1/admin/security/findings/$id";
        $this->getJson($path)->assertOk()->assertJsonStructure(['data' => ['cvss_score', 'component', 'remediation_steps', 'timeline', 'version']]);
        $version = 1;
        foreach (['in_remediation', 'resolved', 'verified'] as $status) {
            $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson($path, ['status' => $status, 'version' => $version++, 'cvss_score' => '8.1', 'remediation_steps' => 'Validate signed tokens and run regression.'])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->getJson($path)->assertOk()->assertJsonCount(4, 'data.timeline');
    }

    public function test_security_events_filter_and_export_whitelist(): void
    {
        $this->admin();
        app(AuditServiceInterface::class)->record(new AuditEventData(action: 'identity.auth.login-failed', actorType: 'ADMIN', resourceType: 'identity.user', correlationId: (string) Uuid::uuid7(), outcome: 'failure', metadata: ['password' => 'never-return-this']));
        $this->getJson('/v1/admin/security/events?event_type=login_failed')->assertOk()->assertJsonStructure(['data' => ['items', 'next_cursor']]);
        $r = $this->get('/v1/admin/security/events/export?event_type=login_failed')->assertOk();
        $this->assertStringNotContainsString('never-return-this', $r->streamedContent());
    }

    public function test_permissions_enforced_for_each_story(): void
    {
        $this->admin([]);
        $this->getJson('/v1/admin/platform/security-config')->assertForbidden();
        $this->getJson('/v1/admin/security/events')->assertForbidden();
        $this->get('/v1/admin/security/events/export')->assertForbidden();
        $this->getJson('/v1/admin/security/findings/'.$this->finding())->assertForbidden();
    }

    public function test_policy_replay_is_bound_to_actor_and_payload_and_scheduler_cannot_activate_it(): void
    {
        $this->admin();
        $key = (string) Uuid::uuid7();
        $path = '/v1/admin/platform/security-config';
        $body = ['key' => 'auth.password.min_length', 'value' => 15];
        $id = $this->withHeader('Idempotency-Key', $key)->patchJson($path, $body)->assertStatus(202)->json('data.id');
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, $body)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, ['key' => 'auth.password.min_length', 'value' => 16])->assertStatus(422);
        $this->admin();
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, $body)->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->postJson("$path/$id/approve")->assertOk();
        $draft = ConfigurationValueModel::query()->where('change_request_id', $id)->firstOrFail();
        $job = new ConfigurationActivationJob(app(ConfigurationService::class), app(NotificationModuleContract::class));
        $method = new \ReflectionMethod($job, 'activateDraft');
        $method->invoke($job, $draft);
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame(1, DB::connection('platform')->table('platform.configuration_versions')->where('change_request_id', $id)->where('event_type', 'activated')->count());
    }

    public function test_stale_finding_versions_conflicting_keys_and_unresolved_verification_are_rejected(): void
    {
        $this->admin();
        $path = '/v1/admin/security/findings/'.$this->finding();
        $key = (string) Uuid::uuid7();
        $body = ['version' => 1, 'status' => 'in_remediation'];
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, $body)->assertOk()->assertJsonPath('data.version', 2);
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, $body)->assertOk()->assertJsonPath('data.version', 2);
        $this->withHeader('Idempotency-Key', $key)->patchJson($path, ['version' => 2, 'status' => 'resolved'])->assertStatus(422);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson($path, $body)->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson($path, ['version' => 2, 'status' => 'verified'])->assertStatus(422);
    }

    public function test_invalid_policy_and_event_filters_are_rejected(): void
    {
        $this->admin();
        $path = '/v1/admin/platform/security-config';
        foreach ([['key' => 'auth.password.min_length', 'value' => 9], ['key' => 'auth.session.max_active', 'value' => 0], ['key' => 'security.admin.ip_allowlist', 'value' => ['0.0.0.0/33']], ['key' => 'auth.mfa.role_requirements', 'value' => ['nonexistent-role' => 'required']]] as $body) {
            $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson($path, $body)->assertStatus(422);
        }
        $this->getJson('/v1/admin/security/events?cursor=invalid')->assertStatus(422);
        $this->getJson('/v1/admin/security/events?severity=unknown')->assertStatus(422);
    }

    public function test_policy_submission_holds_a_key_lock_against_an_independent_connection(): void
    {
        $this->admin();
        $key = 'auth.password.min_length';
        $this->withHeader('Idempotency-Key', (string) Uuid::uuid7())->patchJson('/v1/admin/platform/security-config', ['key' => $key, 'value' => 13])->assertStatus(202);
        config(['database.connections.security_lock_probe' => config('database.connections.platform')]);
        $probe = DB::connection('security_lock_probe');
        try {
            $probe->beginTransaction();
            $r = $probe->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) AS acquired', ['security-policy:'.$key]);
            $this->assertFalse($r->acquired);
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }DB::purge('security_lock_probe');
        }
    }

    public function test_finding_mutation_rolls_back_if_durable_audit_fails(): void
    {
        $actor = $this->admin();
        $id = $this->finding();
        $audit = \Mockery::mock(AuditModuleContract::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(AuditModuleContract::class, $audit);
        try {
            app(VulnerabilityFindingService::class)->updateStatus($id, VulnerabilityFindingStatus::InRemediation, null, $actor, (string) Uuid::uuid7());
            $this->fail('Audit failure must prevent mutation');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('open', DB::connection('platform')->table('security.vulnerability_findings')->where('id', $id)->value('status'));
    }

    public function test_security_event_classification_and_cursor_paging(): void
    {
        $this->admin();
        $audit = app(AuditServiceInterface::class);
        foreach (['identity.login.failed', 'identity.role.assigned', 'configuration.policy.changed', 'integration.api_key.rotated'] as $action) {
            $audit->record(new AuditEventData(action: $action, actorType: 'ADMIN', resourceType: 'security_test', correlationId: (string) Uuid::uuid7(), outcome: 'success'));
        }
        foreach (['login_failed', 'privilege_change', 'config_change', 'api_key_rotation'] as $type) {
            $items = $this->getJson('/v1/admin/security/events?event_type='.$type.'&limit=1')->assertOk()->json('data.items');
            $this->assertCount(1, $items);
            $this->assertSame($type, $items[0]['event_type']);
        }
        $first = $this->getJson('/v1/admin/security/events?limit=1')->assertOk()->json('data');
        $this->assertNotNull($first['next_cursor']);
        $second = $this->getJson('/v1/admin/security/events?limit=1&cursor='.urlencode($first['next_cursor']))->assertOk()->json('data');
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
    }
}
