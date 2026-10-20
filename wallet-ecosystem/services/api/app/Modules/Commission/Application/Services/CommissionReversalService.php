<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionReversedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionAlreadyReversedException;
use App\Modules\Commission\Domain\Exceptions\CommissionNotFoundException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommissionReversalService
{
    public function __construct(
        private readonly LedgerModuleContract    $ledger,
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface  $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function reverse(
        string $commissionId,
        string $actorId,
        string $reason,
        string $correlationId,
    ): CommissionModel {
        $commission = CommissionModel::find($commissionId);
        if ($commission === null) {
            throw new CommissionNotFoundException($commissionId);
        }

        if ($commission->status === CommissionStatus::Reversed) {
            throw new CommissionAlreadyReversedException($commissionId);
        }

        $originalStatus = $commission->status->value;
        $payableAccount  = "commission_payable:{$commission->agent_id}";

        /** @var CommissionModel $result */
        $result = DB::connection('platform')->transaction(function () use (
            $commission, $actorId, $reason, $correlationId,
            $originalStatus, $payableAccount,
        ) {
            if ($commission->status === CommissionStatus::Pending) {
                $reversalJournalId = $this->postAccrualCompensatingJournal(
                    $commission, $payableAccount, $correlationId,
                );
            } else {
                $reversalJournalId = $this->postAccrualCompensatingJournal(
                    $commission, $payableAccount, $correlationId,
                );
                $this->postFloatClawbackJournal($commission, $payableAccount, $correlationId);
            }

            $commission->update([
                'status'              => CommissionStatus::Reversed->value,
                'reversal_journal_id' => $reversalJournalId,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'commission.reversed',
                actorType:     'admin',
                resourceType:  'commission',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $commission->id,
                metadata:      ['reason' => $reason, 'original_status' => $originalStatus],
            ));

            $this->events->publish(new CommissionReversedEvent(
                commissionId:      $commission->id,
                agentId:           $commission->agent_id,
                reversalJournalId: $reversalJournalId,
                amount:            $commission->amount,
                currencyCode:      $commission->currency_code,
                originalStatus:    $originalStatus,
                correlationId:     $correlationId,
                actorId:           $actorId,
            ));

            $this->metrics->increment('commission.reversed', [
                'original_status' => $originalStatus,
                'currency'        => $commission->currency_code,
            ]);

            return $commission->refresh();
        });

        return $result;
    }

    private function postAccrualCompensatingJournal(
        CommissionModel $commission,
        string $payableAccount,
        string $correlationId,
    ): string {
        $expenseAccount = "commission_expense:{$commission->currency_code}";

        return $this->ledger->postEntry([
            'idempotency_key'       => Str::orderedUuid()->toString(),
            'entry_type'            => 'commission',
            'currency_code'         => $commission->currency_code,
            'transaction_reference' => "commission_reversal_accrual:{$commission->id}",
            'correlation_id'        => $correlationId,
            'description'           => "Commission reversal (accrual) for commission {$commission->id}",
            'lines'                 => [
                ['ledger_account_reference' => $payableAccount,  'direction' => 'debit',  'amount' => $commission->amount],
                ['ledger_account_reference' => $expenseAccount,  'direction' => 'credit', 'amount' => $commission->amount],
            ],
        ]);
    }

    private function postFloatClawbackJournal(
        CommissionModel $commission,
        string $payableAccount,
        string $correlationId,
    ): void {
        $floatWallet = AgentFloatWalletModel::where('agent_id', $commission->agent_id)->firstOrFail();

        $this->ledger->postEntry([
            'idempotency_key'       => Str::orderedUuid()->toString(),
            'entry_type'            => 'commission',
            'currency_code'         => $commission->currency_code,
            'transaction_reference' => "commission_reversal_clawback:{$commission->id}",
            'correlation_id'        => $correlationId,
            'description'           => "Commission clawback for paid commission {$commission->id}",
            'lines'                 => [
                ['ledger_account_reference' => $floatWallet->ledger_account_reference, 'direction' => 'debit',  'amount' => $commission->amount],
                ['ledger_account_reference' => $payableAccount,                         'direction' => 'credit', 'amount' => $commission->amount],
            ],
        ]);
    }
}
