<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Admin\Domain\Enums\AdminActionType;
use App\Modules\Admin\Domain\Enums\AdjustmentStatus;
use App\Modules\Admin\Domain\Models\AdminAction;
use App\Modules\Admin\Domain\Models\AdminWalletAdjustment;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class AdminWalletAdjustmentService
{
    public function __construct(
        private readonly LedgerModuleContract  $ledger,
        private readonly AuditServiceInterface $audit,
        private readonly AdminMetricsInterface $metrics,
    ) {}

    public function createAdjustment(array $data, string $makerId, string $ip): AdminWalletAdjustment
    {
        // Idempotent: return existing if same key
        $existing = AdminWalletAdjustment::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            return $existing;
        }

        $adj = AdminWalletAdjustment::create([
            'id'              => (string) Uuid::uuid7(),
            'idempotency_key' => $data['idempotency_key'],
            'wallet_id'       => $data['wallet_id'],
            'adjustment_type' => $data['adjustment_type'],
            'amount'          => $data['amount'],
            'currency'        => $data['currency'] ?? 'BWP',
            'reason'          => $data['reason'],
            'status'          => AdjustmentStatus::PendingApproval->value,
            'maker_id'        => $makerId,
        ]);

        $this->recordAction(AdminActionType::WalletAdjustmentCreated, $makerId, $adj->id, $ip);
        $this->metrics->adjustmentTotal('created');

        $this->audit->record(new AuditEventData(
            action:        'admin.wallet.adjustment.created',
            actorType:     'ADMIN',
            resourceType:  'admin_wallet_adjustment',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $makerId,
            resourceId:    $adj->id,
            afterState:    ['status' => AdjustmentStatus::PendingApproval->value],
            ipAddress:     $ip,
        ));

        return $adj;
    }

    public function approveAdjustment(string $adjId, string $checkerId, string $reason, string $ip): AdminWalletAdjustment
    {
        return DB::connection('admin')->transaction(function () use ($adjId, $checkerId, $reason, $ip): AdminWalletAdjustment {
            // Step 1: Lock the record
            $adj = AdminWalletAdjustment::lockForUpdate()->find($adjId);

            if ($adj === null) {
                throw new \DomainException('ADJUSTMENT_NOT_FOUND');
            }

            // Step 2: Guard — must be pending approval
            if ($adj->status !== AdjustmentStatus::PendingApproval) {
                throw new \DomainException('NOT_PENDING_APPROVAL');
            }

            // Step 3: Guard — maker ≠ checker
            if ($adj->maker_id === $checkerId) {
                throw new \DomainException('MAKER_CHECKER_VIOLATION');
            }

            // Step 4: Persist intermediate approved state (data-model.md state machine)
            $adj->status      = AdjustmentStatus::Approved;
            $adj->approved_at = now();
            $adj->checker_id  = $checkerId;
            $adj->save();

            // Step 5: Post to ledger — if throws, outer transaction rolls back to pending_approval
            $ledgerTransactionId = $this->postToLedger($adj);

            // Step 6: Persist final posted state
            $adj->status               = AdjustmentStatus::Posted;
            $adj->posted_at            = now();
            $adj->ledger_transaction_id = $ledgerTransactionId;
            $adj->save();

            // Step 7: Emit event + audit
            Log::info('admin.wallet.adjustment.approved', [
                'adjustment_id'        => $adj->id,
                'wallet_id'            => $adj->wallet_id,
                'amount'               => $adj->amount,
                'currency'             => $adj->currency,
                'adjustment_type'      => $adj->adjustment_type,
                'ledger_transaction_id' => $ledgerTransactionId,
                'maker_id'             => $adj->maker_id,
                'checker_id'           => $checkerId,
                'ip'                   => $ip,
            ]);
            $this->recordAction(AdminActionType::WalletAdjustmentApproved, $checkerId, $adj->id, $ip, ['reason' => $reason]);
            $this->metrics->adjustmentTotal('posted');

            $this->audit->record(new AuditEventData(
                action:        'admin.wallet.adjustment.posted',
                actorType:     'ADMIN',
                resourceType:  'admin_wallet_adjustment',
                correlationId: Str::uuid()->toString(),
                outcome:       'success',
                actorId:       $checkerId,
                resourceId:    $adj->id,
                afterState:    ['status' => AdjustmentStatus::Posted->value, 'ledger_transaction_id' => $ledgerTransactionId],
                ipAddress:     $ip,
            ));

            return $adj;
        });
    }

    public function rejectAdjustment(string $adjId, string $checkerId, string $reason, string $ip): AdminWalletAdjustment
    {
        return DB::connection('admin')->transaction(function () use ($adjId, $checkerId, $reason, $ip): AdminWalletAdjustment {
        $adj = AdminWalletAdjustment::lockForUpdate()->find($adjId);

        if ($adj === null) {
            throw new \DomainException('ADJUSTMENT_NOT_FOUND');
        }

        if ($adj->status !== AdjustmentStatus::PendingApproval) {
            throw new \DomainException('NOT_PENDING_APPROVAL');
        }

        if ($adj->maker_id === $checkerId) {
            throw new \DomainException('MAKER_CHECKER_VIOLATION');
        }

        $adj->status           = AdjustmentStatus::Rejected;
        $adj->rejected_at      = now();
        $adj->checker_id       = $checkerId;
        $adj->rejection_reason = $reason;
        $adj->save();

        Log::info('admin.wallet.adjustment.rejected', [
            'adjustment_id'   => $adj->id,
            'wallet_id'       => $adj->wallet_id,
            'amount'          => $adj->amount,
            'currency'        => $adj->currency,
            'adjustment_type' => $adj->adjustment_type,
            'maker_id'        => $adj->maker_id,
            'checker_id'      => $checkerId,
            'reason'          => $reason,
            'ip'              => $ip,
        ]);
        $this->recordAction(AdminActionType::WalletAdjustmentRejected, $checkerId, $adj->id, $ip, ['reason' => $reason]);
        $this->metrics->adjustmentTotal('rejected');

        $this->audit->record(new AuditEventData(
            action:        'admin.wallet.adjustment.rejected',
            actorType:     'ADMIN',
            resourceType:  'admin_wallet_adjustment',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $checkerId,
            resourceId:    $adj->id,
            afterState:    ['status' => AdjustmentStatus::Rejected->value],
            ipAddress:     $ip,
        ));

        return $adj;
        }); // end DB::transaction
    }

    private function postToLedger(AdminWalletAdjustment $adj): string
    {
        // Resolve wallet pocket ledger reference
        $walletRef = DB::connection('wallet')->table('wallet.wallet_pockets')
            ->where('wallet_id', $adj->wallet_id)
            ->where('currency_code', $adj->currency)
            ->value('ledger_account_reference');

        if ($walletRef === null) {
            throw new \RuntimeException("No ledger account for wallet {$adj->wallet_id} currency {$adj->currency}");
        }

        // Resolve admin suspense account
        $suspenseRef = config('ledger.admin_suspense.' . $adj->currency, 'admin_suspense_BWP');

        // Amount in minor units (BCMATH)
        $amountMinor = (int) bcmul($adj->amount, '100', 0);

        if ($adj->adjustment_type === 'credit') {
            // Credit wallet: DR admin.suspense / CR wallet.pocket
            $lines = [
                ['ledger_account_reference' => $suspenseRef, 'direction' => 'debit',  'amount' => $amountMinor],
                ['ledger_account_reference' => $walletRef,   'direction' => 'credit', 'amount' => $amountMinor],
            ];
        } else {
            // Debit wallet: DR wallet.pocket / CR admin.suspense
            $lines = [
                ['ledger_account_reference' => $walletRef,   'direction' => 'debit',  'amount' => $amountMinor],
                ['ledger_account_reference' => $suspenseRef, 'direction' => 'credit', 'amount' => $amountMinor],
            ];
        }

        return $this->ledger->postEntry([
            'idempotency_key'       => "admin_adjustment:{$adj->id}",
            'entry_type'            => 'admin_adjustment',
            'currency_code'         => $adj->currency,
            'transaction_reference' => "ADJ-{$adj->id}",
            'correlation_id'        => Str::uuid()->toString(),
            'description'           => "Admin wallet {$adj->adjustment_type}: {$adj->reason}",
            'lines'                 => $lines,
        ]);
    }

    private function recordAction(
        AdminActionType $type,
        string $actorId,
        string $adjId,
        string $ip,
        array $metadata = [],
    ): void {
        AdminAction::create([
            'id'           => (string) Uuid::uuid7(),
            'action_type'  => $type->value,
            'actor_id'     => $actorId,
            'subject_type' => 'admin_wallet_adjustment',
            'subject_id'   => $adjId,
            'metadata'     => $metadata,
            'ip_address'   => $ip,
            'performed_at' => now(),
        ]);
    }
}
