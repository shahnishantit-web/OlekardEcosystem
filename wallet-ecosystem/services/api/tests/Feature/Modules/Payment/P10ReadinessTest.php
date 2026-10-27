<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Application\Jobs\PaymentAttemptSingleRecoveryJob;
use App\Modules\Payment\Application\Services\PaymentIdempotencyService;
use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Domain\Contracts\QueriesPayment;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentAttemptRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIntentRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIdempotencyRepository;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;

/** Real PostgreSQL persistence, rolled back; no provider initiation or ledger calls. */
final class P10ReadinessTest extends IntegrationTestCase
{
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment'];

    public function test_unknown_recovery_persists_once_preserves_hold_and_operation(): void
    {
        $intent = PaymentIntentModel::create(['id' => (string) Uuid::uuid7(), 'reference' => 'P10-'.substr((string) Uuid::uuid7(), -12), 'purpose_type' => 'test_only',
            'purpose_reference' => (string) Uuid::uuid7(), 'organisation_id' => (string) Uuid::uuid7(),
            'payer_actor_id' => (string) Uuid::uuid7(), 'currency_code' => 'BWP', 'amount_minor' => 100,
            'status' => 'processing', 'idempotency_key' => (string) Uuid::uuid7(),
            'correlation_id' => (string) Uuid::uuid7(), 'expires_at' => now()->addHour()]);
        $operation = (string) Uuid::uuid7();
        $provider = \App\Modules\Payment\Infrastructure\Models\PaymentProviderModel::create([
            'id' => (string) Uuid::uuid7(), 'code' => 'p10-'.substr($operation, -12),
            'display_name' => 'TEST ONLY', 'adapter_identifier' => 'mock_bank', 'adapter_version' => 'test',
            'environment' => 'mock', 'is_enabled' => false, 'is_suspended' => true, 'sort_order' => 1]);
        $account = \App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel::create([
            'id' => (string) Uuid::uuid7(), 'provider_id' => $provider->id,
            'merchant_account_reference' => 'test-only', 'is_active' => false]);
        $hold = (string) Uuid::uuid7();
        $attempt = PaymentAttemptModel::create(['id' => (string) Uuid::uuid7(), 'payment_intent_id' => $intent->id,
            'provider_code' => 'p10-readiness', 'provider_account_id' => $account->id, 'status' => 'submitting', 'operation_id' => $operation, 'wallet_hold_id' => $hold,
            'idempotency_key' => (string) Uuid::uuid7(), 'initiated_at' => now()]);
        $adapter = $this->createMock(QueriesPayment::class);
        $adapter->expects($this->once())->method('queryPaymentStatus')->with($operation)
            ->willReturn(new PaymentStatusResult(PaymentAttemptStatus::Unknown));
        $this->app->instance('payment.adapter.p10-readiness', $adapter);
        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->expects($this->never())->method('releaseHold');
        $processor = $this->createMock(PaymentOutcomeProcessor::class);
        $processor->expects($this->never())->method('handleOutcome');
        $job = new PaymentAttemptSingleRecoveryJob($attempt->id);
        for ($i = 0; $i < 2; $i++) {
            $job->handle(new EloquentPaymentAttemptRepository(), new EloquentPaymentIntentRepository(), $processor, $wallet);
        }
        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Unknown, $attempt->status);
        $this->assertSame($operation, $attempt->operation_id);
        $this->assertSame($hold, $attempt->wallet_hold_id);
        $this->assertSame('processing', $intent->fresh()->status->value);
        $this->assertSame(1, DB::connection('payment')->table('payment.reconciliation_exceptions')->where('payment_attempt_id', $attempt->id)->count());
    }

    public function test_database_replay_survives_cache_outage_without_new_execution(): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnCallback(static fn ($key, $default) => $default);
        $repository = new EloquentPaymentIdempotencyRepository($config);
        $actor = (string) Uuid::uuid7();
        $key = (string) Uuid::uuid7();
        $body = ['amount' => 100];
        $hash = hash('sha256', json_encode($body));
        $repository->saveRecord($actor, 'p10.test', $key, $hash, 201, ['id' => 'original']);
        $repository->saveRecord($actor, 'p10.test', $key, $hash, 201, ['id' => 'duplicate-must-not-replace']);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willThrowException(new \RuntimeException('test cache outage'));
        $cache->method('put')->willThrowException(new \RuntimeException('test cache outage'));
        $locks = $this->createMock(\Illuminate\Contracts\Cache\LockProvider::class);
        $locks->expects($this->never())->method('lock');
        $result = (new PaymentIdempotencyService($repository, $config, $cache, $locks))->check($actor, 'p10.test', $key, $body);
        $this->assertTrue($result->isReplay);
        $this->assertSame(['id' => 'original'], $result->responseBody);
        $this->assertSame(1, DB::connection('payment')->table('payment.payment_idempotency_records')->where('actor_id', $actor)->count());
    }
}
