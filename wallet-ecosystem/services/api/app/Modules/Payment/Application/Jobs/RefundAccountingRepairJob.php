<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T020: Scan refunds with pending_ledger_refund accounting status and retry posting.
 *
 * Does NOT process pending_ledger_refund_fee_blocked rows (those require AD-16 resolution).
 * Uses the same idempotency key 'refund:{refundId}' so replays are safe.
 */
final class RefundAccountingRepairJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function uniqueId(): string
    {
        return 'refund_accounting_repair_scan';
    }

    public function handle(
        PaymentRefundRepositoryInterface $refundRepo,
        PaymentIntentRepositoryInterface $intentRepo,
        LedgerModuleContract             $ledger,
        WalletModuleContract             $wallet,
        ConfigurationServiceInterface    $config,
        MetricsCollectorInterface        $metrics,
    ): void {
        $pending = DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('refund_accounting_status', 'pending_ledger_refund')
            ->limit(200)
            ->pluck('id');

        $successCount = 0;
        $failCount    = 0;

        // T040: Emit accounting_blocked_count metric for AD-16 blocked refunds
        $blockedCount = (int) DB::connection('payment')
            ->table('payment.payment_refunds')
            ->where('refund_accounting_status', 'pending_ledger_refund_fee_blocked')
            ->count();

        $metrics->gauge('payment.refund.accounting_blocked_count', (float) $blockedCount);

        if ($blockedCount > 0) {
            Log::info('RefundAccountingRepairJob: AD-16 blocked refunds skipped', [
                'accounting_blocked_count' => $blockedCount,
                'note'                     => 'pending_ledger_refund_fee_blocked requires AD-16 resolution',
            ]);
        }

        foreach ($pending as $refundId) {
            $refund = $refundRepo->findById($refundId);
            if ($refund === null) {
                continue;
            }

            try {
                $intent = $intentRepo->findById($refund->payment_intent_id);
                if ($intent === null) {
                    throw new \RuntimeException("Intent not found for refund {$refundId}");
                }

                $currencyCode  = $intent->currency_code ?? 'BWP';
                $walletId      = $wallet->findPrimaryWalletId($intent->payer_actor_id);
                $creditRef     = $walletId !== null
                    ? $wallet->getPocketLedgerAccountRef($walletId, $currencyCode)
                    : null;
                $debitRef      = $config->getOrDefault('payment.credit_account_' . $currencyCode, null);

                if ($creditRef === null || $debitRef === null) {
                    throw new \RuntimeException("Ledger account refs not resolved for refund {$refundId}");
                }

                $journalId = $ledger->postEntry([
                    'idempotency_key'       => "refund:{$refundId}",
                    'entry_type'            => 'credit',
                    'currency_code'         => $currencyCode,
                    'transaction_reference' => "refund:{$refundId}",
                    'correlation_id'        => "repair:{$refundId}",
                    'description'           => "Repair: wallet refund compensating entry for {$refundId}",
                    'lines'                 => [
                        [
                            'ledger_account_reference' => $debitRef,
                            'direction'                => 'debit',
                            'amount'                   => $refund->amount_minor,
                        ],
                        [
                            'ledger_account_reference' => $creditRef,
                            'direction'                => 'credit',
                            'amount'                   => $refund->amount_minor,
                        ],
                    ],
                ]);

                DB::connection('payment')->transaction(function () use ($refund, $journalId, $refundRepo): void {
                    $refund->refund_accounting_status = 'ledger_refund_posted';
                    $refund->refund_ledger_journal_id = $journalId;
                    $refundRepo->save($refund);
                });

                Log::info('RefundAccountingRepairJob: posted journal', [
                    'refund_id'  => $refundId,
                    'journal_id' => $journalId,
                ]);

                $successCount++;

            } catch (\Throwable $e) {
                Log::error('RefundAccountingRepairJob: repair failed', [
                    'refund_id' => $refundId,
                    'error'     => $e->getMessage(),
                ]);
                $failCount++;
            }
        }

        Log::info('RefundAccountingRepairJob: completed', [
            'processed' => $pending->count(),
            'success'   => $successCount,
            'failed'    => $failCount,
        ]);
    }
}
