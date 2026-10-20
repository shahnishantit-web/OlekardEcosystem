<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionPaidEvent;
use App\Modules\Commission\Domain\Events\CommissionPayoutApprovedEvent;
use App\Modules\Commission\Domain\Events\CommissionPayoutCancelledEvent;
use App\Modules\Commission\Domain\Events\CommissionPayoutDeferredEvent;
use App\Modules\Commission\Domain\Events\CommissionPayoutFailedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionAlreadyProcessedException;
use App\Modules\Commission\Domain\Exceptions\CommissionNotFoundException;
use App\Modules\Commission\Domain\Exceptions\CommissionPayoutNotFoundException;
use App\Modules\Commission\Domain\Exceptions\PayoutCancellationNotAllowedException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommissionPayoutService
{
    public function __construct(
        private readonly LedgerModuleContract   $ledger,
        private readonly AuditServiceInterface  $audit,
        private readonly EventPublisherInterface $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function approveAndExecute(
        string $adminId,
        string $agentId,
        array  $commissionIds,
        string $correlationId,
    ): CommissionPayoutModel {
        $commissions = CommissionModel::whereIn('id', $commissionIds)
            ->where('agent_id', $agentId)
            ->get();

        if ($commissions->count() !== count($commissionIds)) {
            throw new CommissionNotFoundException('one-or-more');
        }

        foreach ($commissions as $commission) {
            if ($commission->status !== CommissionStatus::Pending) {
                throw new CommissionAlreadyProcessedException($commission->id);
            }
        }

        $totalAmount  = (int) $commissions->sum('amount');
        /** @var CommissionModel $firstCommission */
        $firstCommission = $commissions->first();
        $currencyCode    = $firstCommission->currency_code;
        $payoutId        = Str::orderedUuid()->toString();

        /** @var CommissionPayoutModel $result */
        $result = DB::connection('platform')->transaction(function () use (
            $payoutId, $adminId, $agentId, $commissions,
            $totalAmount, $currencyCode, $correlationId,
        ) {
            $agent = AgentModel::find($agentId);

            if ($agent === null || $agent->status === AgentStatus::Suspended) {
                $payout = CommissionPayoutModel::create([
                    'id'              => $payoutId,
                    'agent_id'        => $agentId,
                    'approved_by'     => $adminId,
                    'total_amount'    => $totalAmount,
                    'currency_code'   => $currencyCode,
                    'status'          => CommissionPayoutStatus::Deferred->value,
                    'idempotency_key' => $correlationId,
                ]);

                CommissionModel::whereIn('id', $commissions->pluck('id')->all())
                    ->update(['status' => CommissionStatus::Approved->value, 'payout_id' => $payoutId]);

                $this->events->publish(new CommissionPayoutDeferredEvent(
                    payoutId:      $payoutId,
                    agentId:       $agentId,
                    totalAmount:   $totalAmount,
                    currencyCode:  $currencyCode,
                    reason:        'agent_suspended',
                    correlationId: $correlationId,
                    actorId:       $adminId,
                ));

                $this->metrics->increment('commission.payout.deferred', ['currency' => $currencyCode]);

                return $payout;
            }

            $floatWallet    = AgentFloatWalletModel::where('agent_id', $agentId)->firstOrFail();
            $payableAccount = "commission_payable:{$agentId}";

            $payout = CommissionPayoutModel::create([
                'id'              => $payoutId,
                'agent_id'        => $agentId,
                'approved_by'     => $adminId,
                'total_amount'    => $totalAmount,
                'currency_code'   => $currencyCode,
                'status'          => CommissionPayoutStatus::Approved->value,
                'idempotency_key' => $correlationId,
            ]);

            CommissionModel::whereIn('id', $commissions->pluck('id')->all())
                ->update(['status' => CommissionStatus::Approved->value, 'payout_id' => $payoutId]);

            try {
                $journalId = $this->ledger->postEntry([
                    'idempotency_key'       => Str::orderedUuid()->toString(),
                    'entry_type'            => 'commission',
                    'currency_code'         => $currencyCode,
                    'transaction_reference' => "commission_payout:{$payoutId}",
                    'correlation_id'        => $correlationId,
                    'description'           => "Commission payout {$payoutId} to agent {$agentId}",
                    'lines'                 => [
                        ['ledger_account_reference' => $payableAccount,                          'direction' => 'debit',  'amount' => $totalAmount],
                        ['ledger_account_reference' => $floatWallet->ledger_account_reference,   'direction' => 'credit', 'amount' => $totalAmount],
                    ],
                ]);
            } catch (\Throwable $e) {
                $payout->update(['status' => CommissionPayoutStatus::Failed->value]);

                $this->events->publish(new CommissionPayoutFailedEvent(
                    payoutId:      $payoutId,
                    agentId:       $agentId,
                    errorMessage:  $e->getMessage(),
                    correlationId: $correlationId,
                    actorId:       $adminId,
                ));

                $this->metrics->increment('commission.payout.failed', ['currency' => $currencyCode]);

                return $payout->refresh();
            }

            $payout->update([
                'status'      => CommissionPayoutStatus::Completed->value,
                'executed_at' => now(),
                'journal_id'  => $journalId,
            ]);

            CommissionModel::whereIn('id', $commissions->pluck('id')->all())
                ->update(['status' => CommissionStatus::Paid->value]);

            $this->audit->record(new AuditEventData(
                action:        'commission.payout.executed',
                actorType:     'admin',
                resourceType:  'commission_payout',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $adminId,
                resourceId:    $payoutId,
                metadata:      ['total_amount' => $totalAmount, 'commission_count' => count($commissions)],
            ));

            $this->events->publish(new CommissionPayoutApprovedEvent(
                payoutId:        $payoutId,
                agentId:         $agentId,
                totalAmount:     $totalAmount,
                currencyCode:    $currencyCode,
                commissionCount: count($commissions),
                correlationId:   $correlationId,
                actorId:         $adminId,
            ));

            foreach ($commissions as $commission) {
                $this->events->publish(new CommissionPaidEvent(
                    commissionId:  $commission->id,
                    agentId:       $agentId,
                    payoutId:      $payoutId,
                    amount:        $commission->amount,
                    currencyCode:  $currencyCode,
                    journalId:     $journalId,
                    correlationId: $correlationId,
                    actorId:       $adminId,
                ));
            }

            $this->metrics->increment('commission.payout.completed', ['currency' => $currencyCode]);

            return $payout->refresh();
        });

        return $result;
    }

    public function executeDeferred(string $payoutId): CommissionPayoutModel
    {
        $payout = CommissionPayoutModel::find($payoutId);
        if ($payout === null) {
            throw new CommissionPayoutNotFoundException($payoutId);
        }

        $floatWallet    = AgentFloatWalletModel::where('agent_id', $payout->agent_id)->firstOrFail();
        $payableAccount = "commission_payable:{$payout->agent_id}";
        $correlationId  = Str::orderedUuid()->toString();

        /** @var CommissionPayoutModel $result */
        $result = DB::connection('platform')->transaction(function () use (
            $payout, $floatWallet, $payableAccount, $correlationId,
        ) {
            try {
                $journalId = $this->ledger->postEntry([
                    'idempotency_key'       => Str::orderedUuid()->toString(),
                    'entry_type'            => 'commission',
                    'currency_code'         => $payout->currency_code,
                    'transaction_reference' => "commission_payout:{$payout->id}",
                    'correlation_id'        => $correlationId,
                    'description'           => "Deferred commission payout {$payout->id}",
                    'lines'                 => [
                        ['ledger_account_reference' => $payableAccount,                         'direction' => 'debit',  'amount' => $payout->total_amount],
                        ['ledger_account_reference' => $floatWallet->ledger_account_reference,  'direction' => 'credit', 'amount' => $payout->total_amount],
                    ],
                ]);
            } catch (\Throwable $e) {
                $payout->update(['status' => CommissionPayoutStatus::Failed->value]);
                return $payout->refresh();
            }

            $payout->update([
                'status'      => CommissionPayoutStatus::Completed->value,
                'executed_at' => now(),
                'journal_id'  => $journalId,
            ]);

            CommissionModel::where('payout_id', $payout->id)
                ->update(['status' => CommissionStatus::Paid->value]);

            $this->events->publish(new CommissionPayoutApprovedEvent(
                payoutId:        $payout->id,
                agentId:         $payout->agent_id,
                totalAmount:     $payout->total_amount,
                currencyCode:    $payout->currency_code,
                commissionCount: CommissionModel::where('payout_id', $payout->id)->count(),
                correlationId:   $correlationId,
            ));

            return $payout->refresh();
        });

        return $result;
    }

    public function cancel(string $payoutId, string $actorId, string $correlationId): CommissionPayoutModel
    {
        $payout = CommissionPayoutModel::find($payoutId);
        if ($payout === null) {
            throw new CommissionPayoutNotFoundException($payoutId);
        }

        if ($payout->status !== CommissionPayoutStatus::Deferred) {
            throw new PayoutCancellationNotAllowedException($payoutId, $payout->status->value);
        }

        /** @var CommissionPayoutModel $result */
        $result = DB::connection('platform')->transaction(function () use ($payout, $actorId, $correlationId) {
            $payout->update([
                'status'       => CommissionPayoutStatus::Cancelled->value,
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
            ]);

            CommissionModel::where('payout_id', $payout->id)
                ->where('status', '!=', CommissionStatus::Reversed->value)
                ->update(['status' => CommissionStatus::Pending->value, 'payout_id' => null]);

            $this->audit->record(new AuditEventData(
                action:        'commission.payout.cancelled',
                actorType:     'admin',
                resourceType:  'commission_payout',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $payout->id,
            ));

            $this->events->publish(new CommissionPayoutCancelledEvent(
                payoutId:      $payout->id,
                agentId:       $payout->agent_id,
                reason:        'admin_cancelled',
                correlationId: $correlationId,
                actorId:       $actorId,
            ));

            return $payout->refresh();
        });

        return $result;
    }
}
