<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Integration\Application\Services\WebhookApplicationService;
use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\WebhookEventRepositoryInterface;
use App\Modules\Integration\Infrastructure\Jobs\ProcessWebhookEventJob;
use App\Modules\Integration\Infrastructure\Jobs\DeliverAdminRetryIntentsJob;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Infrastructure\Security\SecretProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

final class P10WebhookIngressTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment'];
    private PaymentProviderAccountModel $account;
    private IntegrationModel $connector;
    private string $attemptId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->attemptId = (string) Uuid::uuid7();
        $provider = PaymentProviderModel::create(['id' => (string) Uuid::uuid7(), 'code' => 'p10-'.substr($this->attemptId, -12),
            'display_name' => 'TEST ONLY', 'adapter_identifier' => 'mock_bank', 'adapter_version' => 'test',
            'environment' => 'mock', 'is_enabled' => false, 'is_suspended' => true, 'is_default' => false, 'sort_order' => 1]);
        $this->account = PaymentProviderAccountModel::create(['id' => (string) Uuid::uuid7(), 'provider_id' => $provider->id,
            'merchant_account_reference' => 'test-merchant', 'callback_secret_reference' => 'P10_TEST_SECRET', 'is_active' => false]);
        $this->connector = IntegrationModel::create(['id' => (string) Uuid::uuid7(), 'provider_type' => 'payment',
            'provider_slug' => $provider->code, 'display_name' => 'TEST ONLY', 'credentials_reference' => 'P10_TEST_SECRET',
            'status' => 'active', 'is_default' => false, 'configuration' => ['payment_provider_account_id' => $this->account->id,
                'payment_provider_code' => $provider->code, 'environment' => 'mock']]);
        $secret = $this->createMock(SecretProviderInterface::class);
        $secret->method('get')->with('P10_TEST_SECRET')->willReturn('p10-test-only-secret');
        $this->app->instance(SecretProviderInterface::class, $secret);
    }

    private function body(array $changes = []): string
    {
        return json_encode(array_replace(['event_id' => 'event-1', 'transaction_ref' => $this->attemptId,
            'merchant_account' => 'test-merchant', 'amount' => 100, 'currency' => 'BWP', 'status' => 'success'], $changes), JSON_THROW_ON_ERROR);
    }

    private function sendRaw(string $body, ?string $signature = null, ?string $url = null)
    {
        return $this->call('POST', $url ?? '/v1/webhooks/'.$this->connector->provider_slug, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'p10-test-only-secret')], $body);
    }

    private function attempt(): void
    {
        $intent = PaymentIntentModel::create(['id' => (string) Uuid::uuid7(), 'reference' => 'P10-'.substr($this->attemptId, -12),
            'purpose_type' => 'test_only', 'purpose_reference' => (string) Uuid::uuid7(), 'organisation_id' => (string) Uuid::uuid7(),
            'payer_actor_id' => (string) Uuid::uuid7(), 'currency_code' => 'BWP', 'amount_minor' => 100,
            'status' => 'processing', 'idempotency_key' => $this->attemptId, 'correlation_id' => (string) Uuid::uuid7(), 'expires_at' => now()->addHour()]);
        PaymentAttemptModel::create(['id' => $this->attemptId, 'payment_intent_id' => $intent->id,
            'provider_code' => $this->connector->provider_slug, 'provider_account_id' => $this->account->id,
            'provider_reference_id' => $this->attemptId, 'status' => 'processing', 'idempotency_key' => $this->attemptId, 'initiated_at' => now()]);
    }

    private function process(string $receiptId): void
    {
        (new ProcessWebhookEventJob($receiptId))->handle(app(IntegrationRegistryInterface::class), app(OutboxEventPublisher::class), app(MetricsCollectorInterface::class));
    }

    public function test_invalid_signature_never_creates_receipt_or_mutates_money(): void
    {
        $before = WebhookEventModel::count();
        $processor = $this->createMock(PaymentOutcomeProcessor::class);
        $processor->expects($this->never())->method('handleOutcome');
        $this->app->instance(PaymentOutcomeProcessor::class, $processor);
        $this->sendRaw($this->body(), 'invalid')->assertStatus(401);
        $this->assertSame($before, WebhookEventModel::count());
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    public function test_receipt_is_durable_encrypted_and_keeps_recovery_intent_before_ack(): void
    {
        $response = $this->sendRaw($this->body())->assertOk();
        $id = $response->json('data.webhook_event_id');
        $row = DB::connection('platform')->table('integration.webhook_events')->where('id', $id)->first();
        $this->assertSame('[restricted payment evidence]', $row->raw_payload);
        $this->assertStringNotContainsString('transaction_ref', $row->encrypted_evidence);
        $this->assertSame($this->body(), WebhookEventModel::findOrFail($id)->encrypted_evidence);
        $this->assertTrue(DB::connection('platform')->table('integration.admin_retry_intents')->where('webhook_event_id', $id)->exists());
        $this->assertTrue(DB::connection('platform')->table('platform.outbox_events')->where('payload->webhook_event_id', $id)->exists());
        $this->assertArrayNotHasKey('encrypted_evidence', WebhookEventModel::findOrFail($id)->toArray());
    }

    public function test_duplicate_and_alias_delivery_return_one_account_scoped_receipt(): void
    {
        $first = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $this->assertSame($first, $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id'));
        $alias = $this->connector->replicate(); $alias->id = (string) Uuid::uuid7(); $alias->provider_slug .= '-alias'; $alias->save();
        $this->assertSame($first, $this->sendRaw($this->body(), null, '/v1/webhooks/'.$alias->provider_slug)->assertOk()->json('data.webhook_event_id'));
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
    }

    public function test_reused_event_identity_with_different_body_is_rejected(): void
    {
        $first = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $this->sendRaw($this->body(['amount' => 101]))->assertStatus(409);
        $this->assertSame($this->body(), WebhookEventModel::findOrFail($first)->encrypted_evidence);
    }

    public function test_legacy_url_uses_e25_without_a_parallel_payment_receipt(): void
    {
        $this->sendRaw($this->body(), null, '/api/v1/payment/callbacks/'.$this->connector->provider_slug)->assertStatus(202)->assertJsonStructure(['receipt_id']);
    }

    public function test_inactive_connector_requires_explicit_resolution_permission(): void
    {
        $this->connector->status = 'inactive'; $this->connector->save();
        $this->sendRaw($this->body())->assertStatus(404);
        $config = $this->connector->configuration; $config['allow_resolution_when_inactive'] = true;
        $this->connector->configuration = $config; $this->connector->save();
        app('cache')->forget('integration_registry:slug:'.$this->connector->provider_slug);
        $this->sendRaw($this->body())->assertOk();
    }

    public function test_storage_failure_does_not_acknowledge(): void
    {
        $repository = $this->createMock(WebhookEventRepositoryInterface::class);
        $repository->method('save')->willThrowException(new \RuntimeException('test storage unavailable'));
        $this->app->instance(WebhookEventRepositoryInterface::class, $repository);
        $this->sendRaw($this->body())->assertStatus(500);
        Queue::assertNotPushed(ProcessWebhookEventJob::class);
    }

    public function test_queue_outage_retains_receipt_for_existing_e25_recovery(): void
    {
        $originalDispatcher = $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher = $this->createMock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('test queue unavailable'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $id = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $this->assertSame('dispatch_unavailable', WebhookEventModel::findOrFail($id)->last_error);
        $this->assertTrue(DB::connection('platform')->table('integration.admin_retry_intents')->where('webhook_event_id', $id)->whereNull('dispatched_at')->exists());
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $originalDispatcher);
        (new DeliverAdminRetryIntentsJob())->handle();
        $this->assertSame('received', WebhookEventModel::findOrFail($id)->status->value);
        $this->assertNotNull(DB::connection('platform')->table('integration.admin_retry_intents')->where('webhook_event_id', $id)->value('dispatched_at'));
    }

    #[DataProvider('unsafeEvidence')]
    public function test_unsafe_evidence_is_quarantined_without_orchestration(array $changes, string $error): void
    {
        $this->attempt();
        $processor = $this->createMock(PaymentOutcomeProcessor::class);
        $processor->expects($this->never())->method('handleOutcome');
        $this->app->instance(PaymentOutcomeProcessor::class, $processor);
        $id = $this->sendRaw($this->body($changes))->assertOk()->json('data.webhook_event_id');
        $this->process($id);
        $this->assertSame('quarantine:'.$error, WebhookEventModel::findOrFail($id)->last_error);
        $this->assertSame('processing', PaymentAttemptModel::findOrFail($this->attemptId)->status->value);
    }

    public static function unsafeEvidence(): array
    {
        return [[['amount' => 101], 'money_mismatch'], [['currency' => 'USD'], 'money_mismatch'],
            [['merchant_account' => 'other-merchant'], 'account_mismatch'], [['status' => 'pending'], 'unresolved_outcome'],
            [['status' => 'unknown'], 'unresolved_outcome']];
    }

    public function test_account_a_signature_cannot_resolve_an_attempt_for_account_b(): void
    {
        $this->attempt();
        $other = $this->account->replicate(); $other->id = (string) Uuid::uuid7(); $other->save();
        PaymentAttemptModel::whereKey($this->attemptId)->update(['provider_account_id' => $other->id]);
        $processor = $this->createMock(PaymentOutcomeProcessor::class);
        $processor->expects($this->never())->method('handleOutcome');
        $this->app->instance(PaymentOutcomeProcessor::class, $processor);
        $id = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $this->process($id);
        $this->assertSame('quarantine:account_mismatch', WebhookEventModel::findOrFail($id)->last_error);
    }

    public function test_queued_legacy_evidence_is_quarantined_using_existing_database_status(): void
    {
        $id = (string) Uuid::uuid7();
        DB::connection('payment')->table('payment.payment_callbacks')->insert(['id' => $id,
            'provider_code' => $this->connector->provider_slug, 'raw_body' => $this->body(), 'headers' => '{}',
            'status' => 'received', 'received_at' => now(), 'created_at' => now()]);
        (new \App\Modules\Payment\Application\Jobs\PaymentCallbackProcessingJob($id))->handle();
        $this->assertSame('dead_lettered', DB::connection('payment')->table('payment.payment_callbacks')->where('id', $id)->value('status'));
    }

    public function test_matched_receipt_handoff_occurs_once_and_keeps_server_money(): void
    {
        $this->attempt();
        $processor = $this->createMock(PaymentOutcomeProcessor::class);
        $processor->expects($this->once())->method('handleOutcome')->with($this->callback(fn ($data) => $data['captured_amount_minor'] === 100 && $data['payment_attempt_id'] === $this->attemptId));
        $this->app->instance(PaymentOutcomeProcessor::class, $processor);
        $id = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $this->process($id); $this->process($id);
        $this->assertSame('processed', WebhookEventModel::findOrFail($id)->status->value);
    }

    public function test_quarantine_and_retry_require_authorization_and_preserve_evidence(): void
    {
        $id = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $url = '/v1/admin/webhooks/deliveries/'.$id;
        $this->postJson($url.'/quarantine')->assertStatus(401);
        $admin = $this->makeAdminWithPermission('integrations.manage');
        $this->withHeaders(['X-Test-User-Id' => $admin->id, 'X-Test-Actor-Type' => 'ADMIN'])->postJson($url.'/quarantine')->assertOk();
        $this->assertSame('dead_letter', WebhookEventModel::findOrFail($id)->status->value);
        $this->postJson($url.'/retry')->assertStatus(202);
        $this->assertSame($this->body(), WebhookEventModel::findOrFail($id)->encrypted_evidence);
    }

    public function test_authenticated_actor_without_permission_cannot_quarantine(): void
    {
        $id = $this->sendRaw($this->body())->assertOk()->json('data.webhook_event_id');
        $limited = $this->makeAdminWithPermission();
        $this->withHeaders(['X-Test-User-Id' => $limited->id, 'X-Test-Actor-Type' => 'ADMIN'])
            ->postJson('/v1/admin/webhooks/deliveries/'.$id.'/quarantine')->assertStatus(403);
        $this->assertSame('received', WebhookEventModel::findOrFail($id)->status->value);
    }
}
