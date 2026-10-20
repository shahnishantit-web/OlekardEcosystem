<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Infrastructure\Http\Middleware\ApiClientAuthMiddleware;
use App\Modules\Integration\Infrastructure\Http\Middleware\DualRateLimitMiddleware;
use App\Modules\Integration\Infrastructure\Jobs\DeliverAdminRetryIntentsJob;
use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use App\Modules\Integration\Infrastructure\Jobs\RetryWebhookEventJob;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Ops\Application\Jobs\ProviderHealthCheckJob;
use App\Modules\Ops\Domain\Contracts\HealthCheckableProviderInterface;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $manager = app('redis');
        $property = new \ReflectionProperty($manager, 'connections');
        $connections = $property->getValue($manager);
        $connections['identity'] = new FakeRedisConnection;
        $property->setValue($manager, $connections);
        if (! Schema::connection('platform')->hasTable('integration.api_clients')) {
            foreach (glob(app_path('Modules/Integration/Infrastructure/Migrations/2026_10_01_*.php')) as $migration) {
                (require $migration)->up();
            }
        }
        if (! Schema::connection('platform')->hasTable('ops.provider_health_checks')) {
            DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS ops');
            (require app_path('Modules/Ops/Infrastructure/Migrations/2026_10_03_100400_create_provider_health_checks_table.php'))->up();
        }
        if (! Schema::connection('platform')->hasColumn('integration.api_clients', 'ip_whitelist')) {
            (require app_path('Modules/Integration/Infrastructure/Migrations/2026_10_15_020000_admin_integration_management.php'))->up();
        }
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        Queue::fake();
    }

    private function admin(array $perms = ['integrations.manage']): string
    {
        $actor = $this->createAuthenticatedAdmin($perms);
        $this->actingAs($actor);

        return $actor->id;
    }

    private function partner(string $slug = 'test-provider'): IntegrationModel
    {
        return IntegrationModel::create(['id' => (string) Uuid::uuid7(), 'provider_type' => 'sms', 'provider_slug' => $slug.'-'.Uuid::uuid7(), 'display_name' => 'Test partner', 'credentials_reference' => 'test/reference', 'status' => 'active', 'is_default' => false]);
    }

    private function webhook(string $status = 'dead_letter'): WebhookEventModel
    {
        return WebhookEventModel::create(['id' => (string) Uuid::uuid7(), 'provider_type' => 'sms', 'provider_slug' => 'test-provider', 'event_type' => 'payment.completed', 'raw_payload' => '{}', 'idempotency_key' => (string) Uuid::uuid7(), 'status' => $status, 'retry_count' => 2, 'max_retry_count' => 5, 'received_at' => now()]);
    }

    private function authStatus(string $key, string $secret, string $ip): int
    {
        $request = Request::create('/m2m', 'GET', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_AUTHORIZATION' => 'Bearer '.base64_encode($key.':'.$secret)]);

        return app(ApiClientAuthMiddleware::class)->handle($request, static fn () => response()->json(['ok' => true]))->getStatusCode();
    }

    public function test_create_partner_permissions_ip_and_hash_only_secret(): void
    {
        $partner = $this->partner();
        $actor = $this->admin();
        $response = $this->postJson('/v1/admin/integrations/api-clients', ['name' => 'Partner app', 'permissions' => ['integrations.manage'], 'integration_id' => $partner->id, 'ip_whitelist' => ['192.0.2.0/24', '2001:db8::/32'], 'rate_limit_per_minute' => 60])->assertCreated();
        $id = $response->json('data.id');
        $secret = $response->json('data.raw_secret');
        $key = $response->json('data.api_key');
        $client = ApiClientModel::findOrFail($id);
        $this->assertTrue(password_verify($secret, $client->secret_hash));
        $this->assertArrayNotHasKey('secret_hash', $client->toArray());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/v1/admin/integrations/api-clients/'.$id)->assertOk()->assertJsonMissingPath('data.raw_secret')->assertJsonMissingPath('data.secret_hash');
        $this->assertEquals(200, $this->authStatus($key, $secret, '192.0.2.15'));
        $this->assertEquals(401, $this->authStatus($key, $secret, '198.51.100.1'));
        $this->assertEquals(200, $this->authStatus($key, $secret, '2001:db8::9'));
    }

    public function test_rotation_and_terminal_revocation_preserve_auth_contract(): void
    {
        $this->admin();
        $partner = $this->partner();
        $response = $this->postJson('/v1/admin/integrations/api-clients', ['name' => 'Rotate app', 'permissions' => [], 'integration_id' => $partner->id, 'ip_whitelist' => []])->assertCreated();
        $id = $response->json('data.id');
        $key = $response->json('data.api_key');
        $old = $response->json('data.raw_secret');
        $rotate = $this->postJson('/v1/admin/integrations/api-clients/'.$id.'/rotate')->assertOk();
        $new = $rotate->json('data.raw_secret');
        $this->assertEquals(401, $this->authStatus($key, $old, '192.0.2.1'));
        $this->assertEquals(200, $this->authStatus($key, $new, '192.0.2.1'));
        $this->deleteJson('/v1/admin/integrations/api-clients/'.$id)->assertOk();
        $this->deleteJson('/v1/admin/integrations/api-clients/'.$id)->assertOk();
        $this->assertEquals(401, $this->authStatus($key, $new, '192.0.2.1'));
        $this->postJson('/v1/admin/integrations/api-clients/'.$id.'/rotate')->assertConflict();
        $this->patchJson('/v1/integrations/clients/'.$id.'/status', ['status' => 'active'])->assertConflict();
    }

    public function test_validation_and_forbidden_paths(): void
    {
        $this->getJson('/v1/admin/integrations/providers')->assertUnauthorized();
        $this->admin([]);
        foreach (['/v1/admin/integrations/api-clients', '/v1/admin/integrations/providers', '/v1/admin/webhooks/deliveries'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/v1/admin/integrations/api-clients', [])->assertForbidden();
        $this->admin();
        $partner = $this->partner();
        $this->postJson('/v1/admin/integrations/api-clients', ['name' => 'Bad', 'integration_id' => $partner->id, 'permissions' => ['not.a.real.permission'], 'ip_whitelist' => ['not-an-ip']])->assertUnprocessable();
    }

    public function test_log_endpoint_status_event_filters_and_attempts(): void
    {
        $event = $this->webhook();
        $this->webhook('processed');
        $this->admin();
        DB::connection('platform')->table('integration.webhook_processing_attempts')->insert(['id' => (string) Uuid::uuid7(), 'webhook_event_id' => $event->id, 'attempt_number' => 2, 'outcome' => 'failure', 'attempted_at' => now()]);
        $this->getJson('/v1/admin/webhooks/deliveries?status=failed&event_type=payment.completed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.endpoint', '/v1/webhooks/test-provider')->assertJsonPath('data.0.attempts', 1)->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/v1/admin/webhooks/deliveries?status=invalid')->assertUnprocessable();
    }

    public function test_manual_retry_is_durable_and_processed_jobs_do_not_repeat(): void
    {
        $event = $this->webhook('failed');
        $this->admin();
        $this->postJson('/v1/admin/webhooks/deliveries/'.$event->id.'/retry')->assertAccepted();
        $this->postJson('/v1/admin/webhooks/deliveries/'.$event->id.'/retry')->assertConflict();
        app(DeliverAdminRetryIntentsJob::class)->handle();
        app(DeliverAdminRetryIntentsJob::class)->handle();
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
        $counter = (object) ['calls' => 0];
        app()->bind('integration.webhook.handler.test-provider.payment.completed', static fn () => new class($counter)
        {
            public function __construct(private object $counter) {}

            public function handle($event): void
            {
                $this->counter->calls++;
            }
        });
        app()->call([new ProcessWebhookEventJob($event->id), 'handle']);
        app()->call([new ProcessWebhookEventJob($event->id), 'handle']);
        $this->assertEquals(1, $counter->calls);
        $this->postJson('/v1/admin/webhooks/deliveries/'.$event->id.'/retry')->assertConflict();
    }

    public function test_stale_automatic_retry_generation_does_not_enqueue_after_manual_reset(): void
    {
        $event = $this->webhook('failed');
        $this->admin();
        $oldJob = new RetryWebhookEventJob($event->id, 2, 0);
        $this->postJson('/v1/admin/webhooks/deliveries/'.$event->id.'/retry')->assertAccepted();
        $event->refresh();
        $event->status = 'failed';
        $event->retry_count = 2;
        $event->save();
        $oldJob->handle();
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    public function test_health_is_scoped_to_integration_with_24h_buckets_and_safe_errors(): void
    {
        $a = $this->partner('a');
        $b = $this->partner('b');
        $this->admin();
        foreach (['healthy', 'unhealthy', 'healthy'] as $i => $status) {
            DB::connection('platform')->table('ops.provider_health_checks')->insert(['id' => (string) Uuid::uuid7(), 'integration_id' => $a->id, 'provider_type' => 'sms', 'status' => $status, 'error_message' => 'secret=do-not-expose', 'checked_at' => now()->subMinutes(3 - $i), 'created_at' => now()]);
        }
        $health = $this->getJson('/v1/admin/integrations/providers/'.$a->id.'/health')->assertOk()->assertJsonCount(24, 'data.buckets')->assertJsonPath('data.health', 'healthy');
        $this->assertStringNotContainsString('do-not-expose', $health->getContent());
        $this->getJson('/v1/admin/integrations/providers/'.$b->id.'/health')->assertOk()->assertJsonPath('data.health', 'unknown')->assertJsonPath('data.last_checked_at', null);
        $this->getJson('/v1/admin/integrations/providers')->assertOk()->assertJsonMissingPath('data.0.credentials_reference')->assertJsonMissingPath('data.0.configuration');
    }

    public function test_missing_and_invalid_callback_signature_are_rejected_but_valid_is_deduplicated(): void
    {
        $this->withoutMiddleware(DualRateLimitMiddleware::class);
        $partner = $this->partner('signed');
        $endpoint = '/v1/webhooks/'.$partner->provider_slug;
        $this->postJson($endpoint, ['event' => 'notice'])->assertUnauthorized();
        $contract = new class implements ProviderWebhookContract
        {
            public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent
            {
                if (! hash_equals(hash_hmac('sha256', $rawPayload, 'p1020-test-only-key'), $signature)) {
                    throw new WebhookSignatureException('Invalid signature');
                }

                return new NormalizedWebhookEvent(hash('sha256', $rawPayload), (string) Uuid::uuid7(), null, null, 'test-provider', null, 'notice', ProviderStatus::SUCCESS, new \DateTimeImmutable, $rawPayload);
            }
        };
        app()->instance('integration.webhook.'.$partner->provider_slug, $contract);
        $this->withHeader('X-Signature', 'invalid')->postJson($endpoint, ['event' => 'notice'])->assertUnauthorized();
        $body = '{"event":"notice"}';
        $sig = hash_hmac('sha256', $body, 'p1020-test-only-key');
        $first = $this->call('POST', $endpoint, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $body)->assertOk();
        $second = $this->call('POST', $endpoint, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertEquals($first->json('data.webhook_event_id'), $second->json('data.webhook_event_id'));
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    public function test_existing_ops_collector_records_each_actual_partner_probe(): void
    {
        $partner = $this->partner('probe');
        $adapter = $this->createMockForIntersectionOfInterfaces([ProviderAdapterInterface::class, HealthCheckableProviderInterface::class]);
        $adapter->expects($this->once())->method('probe')->with(5)->willReturn(['status' => 'success', 'response_time_ms' => 18, 'error' => null]);
        app()->instance('integration.adapter.'.$partner->provider_slug, $adapter);
        app()->call([new ProviderHealthCheckJob, 'handle']);
        $this->assertDatabaseHas('ops.provider_health_checks', ['integration_id' => $partner->id, 'status' => 'healthy', 'response_time_ms' => 18], 'platform');
    }

    public function test_worker_holds_shared_lock_and_stale_generation_does_not_process(): void
    {
        $event = $this->webhook('received');
        config(['database.connections.integration_lock_probe' => config('database.connections.platform')]);
        $probe = DB::connection('integration_lock_probe');
        $counter = (object) ['calls' => 0];
        app()->instance('integration.webhook.handler.test-provider.payment.completed', new class($probe, $counter, $event->id)
        {
            public function __construct(private $probe, private object $counter, private string $id) {}

            public function handle($event): void
            {
                $locked = $this->probe->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired', ['integration-webhook:'.$this->id])->acquired;
                if ($locked) {
                    $this->probe->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', ['integration-webhook:'.$this->id]);
                    throw new \RuntimeException('Lock unexpectedly acquired');
                }$this->counter->calls++;
            }
        });
        try {
            app()->call([new ProcessWebhookEventJob($event->id, 99, 0), 'handle']);
            $this->assertEquals(0, $counter->calls);
            app()->call([new ProcessWebhookEventJob($event->id, 0, 2), 'handle']);
            $this->assertEquals(1, $counter->calls);
            $this->assertEquals('processed', $event->fresh()->status->value);
        } finally {
            DB::disconnect('integration_lock_probe');
        }
    }

    public function test_real_audit_identifies_actor_and_never_contains_one_time_secret(): void
    {
        $partner = $this->partner();
        $actor = $this->admin();
        $this->instance(AuditServiceInterface::class, new AuditService);
        $response = $this->postJson('/v1/admin/integrations/api-clients', ['name' => 'Audit app', 'permissions' => [], 'integration_id' => $partner->id, 'ip_whitelist' => []])->assertCreated();
        $id = $response->json('data.id');
        $secret = $response->json('data.raw_secret');
        $audit = DB::connection('platform')->table('platform.audit_events')->where('resource_id', $id)->first();
        $this->assertEquals($actor, $audit->actor_id);
        $this->assertStringNotContainsString($secret, json_encode($audit));
    }

    public function test_provider_probe_exception_does_not_leak_into_storage_or_logs(): void
    {
        $partner = $this->partner('failing-probe');
        $adapter = $this->createMockForIntersectionOfInterfaces([ProviderAdapterInterface::class, HealthCheckableProviderInterface::class]);
        $adapter->method('probe')->willThrowException(new \RuntimeException('token=PROBE-SECRET-MUST-NOT-LEAK'));
        app()->instance('integration.adapter.'.$partner->provider_slug, $adapter);
        $messages = [];
        Log::listen(function ($event) use (&$messages): void {
            $messages[] = json_encode([$event->message, $event->context]);
        });
        app()->call([new ProviderHealthCheckJob, 'handle']);
        $this->assertDatabaseHas('ops.provider_health_checks', ['integration_id' => $partner->id, 'status' => 'unhealthy', 'error_message' => 'Provider probe failed'], 'platform');
        $this->assertStringNotContainsString('PROBE-SECRET-MUST-NOT-LEAK', implode("\n", $messages));
    }
}
