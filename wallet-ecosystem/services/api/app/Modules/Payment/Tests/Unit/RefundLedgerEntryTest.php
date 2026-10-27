<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T018 — Wallet refund confirm posts compensating ledger entry; idempotency; bank path deferred.
 *
 *  (a) Wallet confirm posts compensating journal with correct debit/credit accounts + idempotency key
 *  (b) Replay returns existing record — no second journal (idempotency gate)
 *  (c) LedgerModuleContract throws → state = confirmed, accounting_status = pending_ledger_refund
 *  (d) Partial refund posts exact refund amount, not original
 *  (e) Bank refund confirm → pending_ledger_refund_fee_blocked; no journal posted
 */
final class RefundLedgerEntryTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&PaymentAttemptRepositoryInterface $attemptRepo;
    private MockObject&PaymentIntentRepositoryInterface $intentRepo;
    private MockObject&LedgerModuleContract $ledger;
    private MockObject&WalletModuleContract $wallet;
    private MockObject&ConfigurationServiceInterface $config;
    private MockObject&AuditServiceInterface $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundRepo  = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->intentRepo  = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->ledger      = $this->createMock(LedgerModuleContract::class);
        $this->wallet      = $this->createMock(WalletModuleContract::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit       = $this->createMock(AuditServiceInterface::class);
    }

    private function makeService(): RefundService
    {
        return new RefundService(
            attemptRepository:        $this->attemptRepo,
            refundRepository:         $this->refundRepo,
            config:                   $this->config,
            audit:                    $this->audit,
            events:                   $this->createMock(EventPublisherInterface::class),
            container:                $this->createMock(Container::class),
            reconciliationExceptions: $this->createMock(ReconciliationExceptionService::class),
            ledger:                   $this->ledger,
            wallet:                   $this->wallet,
            intentRepository:         $this->intentRepo,
        );
    }

    private function makeRefund(
        string $status = 'processing',
        string $providerCode = 'wallet',
        int $amount = 5000,
    ): PaymentRefundModel {
        $refund = new PaymentRefundModel();
        $refund->id                 = 'refund-001';
        $refund->payment_intent_id  = 'intent-001';
        $refund->payment_attempt_id = 'attempt-001';
        $refund->amount_minor       = $amount;
        $refund->status             = PaymentRefundStatus::from($status);
        return $refund;
    }

    private function makeAttempt(string $providerCode = 'wallet'): PaymentAttemptModel
    {
        $attempt = new PaymentAttemptModel();
        $attempt->id            = 'attempt-001';
        $attempt->amount_minor  = 10000;
        $attempt->currency_code = 'BWP';
        $attempt->provider_code = $providerCode;
        return $attempt;
    }

    private function makeIntent(): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id              = 'intent-001';
        $intent->payer_actor_id  = 'actor-001';
        $intent->currency_code   = 'BWP';
        return $intent;
    }

    // ─── (a) wallet refund posts compensating journal ─────────────────────────

    public function test_wallet_confirm_posts_compensating_journal(): void
    {
        $refund = $this->makeRefund('processing', 'wallet');
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt('wallet'));
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());

        $this->wallet->method('findPrimaryWalletId')->with('actor-001')->willReturn('wallet-001');
        $this->wallet->method('getPocketLedgerAccountRef')->with('wallet-001', 'BWP')->willReturn('acc-pocket-001');
        $this->config->method('getOrDefault')
            ->willReturnCallback(fn($key, $default) => match ($key) {
                'payment.credit_account_BWP' => 'acc-org-001',
                default                      => $default,
            });

        $capturedEntry = null;
        $this->ledger->expects($this->once())
            ->method('postEntry')
            ->willReturnCallback(function (array $entry) use (&$capturedEntry): string {
                $capturedEntry = $entry;
                return 'journal-001';
            });

        $this->makeService()->confirm('refund-001', 'prov-ref-001', 'corr-001');

        $this->assertNotNull($capturedEntry);
        $this->assertSame('refund:refund-001', $capturedEntry['idempotency_key']);
        $this->assertSame('BWP', $capturedEntry['currency_code']);

        $lines = $capturedEntry['lines'];
        $debitLine  = collect($lines)->firstWhere('direction', 'debit');
        $creditLine = collect($lines)->firstWhere('direction', 'credit');

        $this->assertSame('acc-org-001', $debitLine['ledger_account_reference']);
        $this->assertSame('acc-pocket-001', $creditLine['ledger_account_reference']);
        $this->assertSame(5000, $debitLine['amount']);
        $this->assertSame(5000, $creditLine['amount']);
    }

    // ─── (b) idempotency gate — no second journal on replay ──────────────────

    public function test_confirm_replay_does_not_post_second_journal(): void
    {
        $refund = $this->makeRefund('confirmed', 'wallet');
        $this->refundRepo->method('findById')->willReturn($refund);

        $this->ledger->expects($this->never())->method('postEntry');
        $this->refundRepo->expects($this->never())->method('save');

        $result = $this->makeService()->confirm('refund-001', 'prov-ref-001', 'corr-001');
        $this->assertSame(PaymentRefundStatus::Confirmed, $result->status);
    }

    // ─── (c) ledger throws → confirmed state preserved, accounting pending ───

    public function test_ledger_exception_preserves_confirmed_state_sets_pending_accounting(): void
    {
        $refund = $this->makeRefund('processing', 'wallet');
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt('wallet'));
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());

        $this->wallet->method('findPrimaryWalletId')->willReturn('wallet-001');
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('acc-pocket-001');
        $this->config->method('getOrDefault')->willReturn('acc-org-001');

        $this->ledger->method('postEntry')->willThrowException(new \RuntimeException('Ledger unavailable'));

        $result = $this->makeService()->confirm('refund-001', 'prov-ref-001', 'corr-001');

        $this->assertSame(PaymentRefundStatus::Confirmed, $result->status);
        $this->assertSame('pending_ledger_refund', $result->refund_accounting_status);
    }

    // ─── (d) partial refund posts exact refund amount ─────────────────────────

    public function test_partial_refund_posts_exact_refund_amount(): void
    {
        $refund = $this->makeRefund('processing', 'wallet', 3000); // 3000 of 10000
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt('wallet'));
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());
        $this->wallet->method('findPrimaryWalletId')->willReturn('wallet-001');
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('acc-pocket-001');
        $this->config->method('getOrDefault')->willReturn('acc-org-001');
        $this->ledger->method('postEntry')->willReturn('journal-001');

        $capturedEntry = null;
        $this->ledger->expects($this->once())
            ->method('postEntry')
            ->willReturnCallback(function (array $entry) use (&$capturedEntry): string {
                $capturedEntry = $entry;
                return 'journal-001';
            });

        $this->makeService()->confirm('refund-001', 'prov-ref-001', 'corr-001');

        $lines = $capturedEntry['lines'];
        $this->assertSame(3000, $lines[0]['amount']);
        $this->assertSame(3000, $lines[1]['amount']);
    }

    // ─── (e) bank refund → fee_blocked, no journal ────────────────────────────

    public function test_bank_refund_accounting_blocked_pending_ad16(): void
    {
        $refund = $this->makeRefund('processing', 'bank_mno');
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->method('save')->willReturn(null);
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt('bank_mno'));
        $this->intentRepo->method('findById')->willReturn($this->makeIntent());

        $this->ledger->expects($this->never())->method('postEntry');

        $result = $this->makeService()->confirm('refund-001', 'prov-ref-001', 'corr-001');

        $this->assertSame(PaymentRefundStatus::Confirmed, $result->status);
        $this->assertSame('pending_ledger_refund_fee_blocked', $result->refund_accounting_status);
    }
}
