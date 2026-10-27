<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentAttemptRepository;
use App\Modules\Payment\Infrastructure\Repositories\EloquentPaymentIntentRepository;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Modules\Integration\IntegrationTestCase;
use Tests\Support\P10PaymentFixture;

/** Actual ledger API/PostgreSQL/outbox; all financial test writes roll back. */
final class P10LedgerGateTest extends IntegrationTestCase
{
    use P10PaymentFixture;
    protected $connectionsToTransact = [null, 'identity', 'platform', 'payment'];

    public function test_lost_ledger_ack_recovers_one_balanced_journal_and_one_confirmation(): void
    {
        [$intent, $provider, $account] = $this->paymentFixture();
        $intent->status = 'processing'; $intent->save();
        $audit = $this->createMock(AuditServiceInterface::class);
        $this->app->instance(AuditServiceInterface::class, $audit);
        $realLedger = app(LedgerModuleContract::class);
        $debit = (string) Uuid::uuid7(); $credit = (string) Uuid::uuid7();
        $realLedger->registerAccount($debit, 'asset', 'BWP', $intent->payer_actor_id, 'system_float');
        $realLedger->registerAccount($credit, 'income', 'BWP', $intent->organisation_id, 'system_revenue');
        // Wallet contract is a fixture locator only; balances come from real ledger lines.
        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('findPrimaryWalletId')->willReturn((string) Uuid::uuid7());
        $wallet->method('getPocketLedgerAccountRef')->willReturn($debit);
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnCallback(static fn ($key, $default) => $key === 'payment.credit_account_BWP' ? $credit : $default);
        $ledger = $this->createMock(LedgerModuleContract::class);
        $calls = 0;
        $ledger->method('postEntry')->willReturnCallback(function (array $request) use ($realLedger, &$calls): string {
            $id = $realLedger->postEntry($request);
            if (++$calls === 1) throw new \RuntimeException('TEST ONLY: acknowledgement lost after ledger API success');
            return $id;
        });
        $service = new PaymentOrchestrationService(new EloquentPaymentIntentRepository(), new EloquentPaymentAttemptRepository(),
            new BusinessPurposeResolverRegistry(), $wallet, $ledger, $config, $audit, app(OutboxEventPublisher::class), $this->app);
        $attempt = PaymentAttemptModel::create(['id' => (string) Uuid::uuid7(), 'payment_intent_id' => $intent->id,
            'provider_code' => $provider->code, 'provider_account_id' => $account->id, 'status' => 'processing',
            'idempotency_key' => (string) Uuid::uuid7(), 'wallet_hold_id' => (string) Uuid::uuid7(), 'initiated_at' => now()]);
        $outcome = ['payment_intent_id' => $intent->id, 'payment_attempt_id' => $attempt->id, 'outcome' => 'succeeded',
            'provider_reference_id' => 'test-confirmed', 'correlation_id' => $intent->correlation_id];
        $service->handleOutcome($outcome);
        $this->assertSame('succeeded', $intent->fresh()->status->value);
        $this->assertSame('pending_ledger', $intent->fresh()->accounting_status->value);
        $this->assertSame(1, DB::connection('platform')->table('ledger.journals')->where('transaction_reference', $intent->id)->count());
        $service->repairAccountingForIntent($intent->id, $intent->correlation_id);
        $service->repairAccountingForIntent($intent->id, $intent->correlation_id);
        $service->handleOutcome($outcome);
        $intent->refresh();
        $this->assertSame('ledger_posted', $intent->accounting_status->value);
        $this->assertSame('pending', $intent->fulfilment_status->value); // No domain entitlement fabricated.
        $journals = DB::connection('platform')->table('ledger.journals')->where('transaction_reference', $intent->id)->pluck('id');
        $this->assertCount(1, $journals);
        $lines = DB::connection('platform')->table('ledger.journal_lines')->whereIn('journal_id', $journals)->get();
        $this->assertCount(2, $lines);
        $this->assertSame(100, (int) $lines->where('direction', 'debit')->sum('amount'));
        $this->assertSame(100, (int) $lines->where('direction', 'credit')->sum('amount'));
        $this->assertSame(100, $realLedger->getBalance($debit));
        $this->assertSame(100, $realLedger->getBalance($credit));
        $this->assertSame(1, DB::connection('payment')->table('platform.outbox_events')
            ->where('event_name', 'payment.confirmed')->where('aggregate_id', $intent->id)->count());
    }

    public function test_payment_and_common_outbox_rollback_together(): void
    {
        [$intent] = $this->paymentFixture();
        $event = new \App\Modules\Payment\Domain\Events\PaymentCircuitOpenEvent('test-only', 5, 1, $intent->correlation_id);
        try {
            DB::connection('payment')->transaction(function () use ($intent, $event): void {
                $intent->status = 'processing'; $intent->save();
                app(OutboxEventPublisher::class)->publishOnConnection($event, 'payment');
                throw new \RuntimeException('TEST ONLY rollback boundary');
            });
            $this->fail('Expected injected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('TEST ONLY rollback boundary', $error->getMessage());
        }
        $this->assertSame('pending', $intent->fresh()->status->value);
        $this->assertSame(0, DB::connection('payment')->table('platform.outbox_events')->where('correlation_id', $intent->correlation_id)->count());
    }
}
