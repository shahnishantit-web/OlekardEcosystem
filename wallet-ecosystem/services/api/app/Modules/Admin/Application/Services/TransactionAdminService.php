<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Admin\Domain\Enums\AdminActionType;
use App\Modules\Admin\Domain\Models\AdminAction;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transaction\Application\Services\RefundService;
use App\Modules\Transaction\Application\Services\ReversalService;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class TransactionAdminService
{
    public function __construct(
        private readonly ReversalService       $reversalService,
        private readonly RefundService         $refundService,
        private readonly AuditServiceInterface $audit,
        private readonly AdminMetricsInterface $metrics,
        private readonly \App\Modules\Ledger\Domain\Contracts\LedgerModuleContract $ledger,
    ) {}

    public function searchTransactions(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::connection('platform')->table('transaction.transaction_projections as t')
            ->leftJoin('identity.users as u', 'u.id', '=', 't.customer_id')
            ->select([
                't.id', 't.reference', 't.type', 't.status', 't.amount', 't.currency_code',
                't.direction', 't.wallet_id', 't.customer_id', 't.source_module',
                't.created_at', 't.completed_at', 't.failed_at',
                'u.email as customer_email',
            ]);

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($sub) use ($q): void {
                $sub->where('t.reference', 'ilike', "%{$q}%")
                    ->orWhere('t.id::text', 'ilike', "%{$q}%")
                    ->orWhere('u.email', 'ilike', "%{$q}%");
            });
        }

        if (! empty($filters['type'])) {
            $query->where('t.type', $filters['type']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('t.status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->where('t.created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('t.created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        if (! empty($filters['wallet_id'])) {
            $query->where('t.wallet_id', $filters['wallet_id']);
        }

        return $query->orderBy('t.created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    public function getTransaction(string $transactionId): array
    {
        $tx = DB::connection('platform')->table('transaction.transaction_projections')
            ->where('id', $transactionId)
            ->first();

        if ($tx === null) {
            throw new \DomainException('TRANSACTION_NOT_FOUND');
        }

        $entries = $this->ledger->getTransactionHistory($tx->reference);

        return [
            'transaction' => (array) $tx,
            'ledger_entries' => array_map(fn ($e) => (array) $e, $entries),
        ];
    }

    public function investigateFailedTransaction(string $transactionId, string $actorId, string $ip): array
    {
        $tx = DB::connection('platform')->table('transaction.transaction_projections')
            ->where('id', $transactionId)
            ->first();

        if ($tx === null) {
            throw new \DomainException('TRANSACTION_NOT_FOUND');
        }

        $tx = (array) $tx;
        $metadata = is_string($tx['metadata'] ?? null) ? json_decode($tx['metadata'], true) : ($tx['metadata'] ?? []);

        $investigation = [
            'transaction_id'  => $transactionId,
            'reference'       => $tx['reference'],
            'status'          => $tx['status'],
            'type'            => $tx['type'],
            'amount'          => $tx['amount'],
            'currency_code'   => $tx['currency_code'],
            'failed_at'       => $tx['failed_at'] ?? null,
            'failure_reason'  => $metadata['failure_reason'] ?? null,
            'failure_code'    => $metadata['failure_code'] ?? null,
            'provider_ref'    => $metadata['provider_reference'] ?? null,
            'retry_count'     => $metadata['retry_count'] ?? 0,
            'metadata'        => $metadata,
        ];

        $this->recordAction(
            AdminActionType::InvestigateTransaction,
            $actorId,
            'transaction',
            $transactionId,
            'admin investigation',
            $ip,
        );

        $this->metrics->transactionAction('investigate');

        return $investigation;
    }

    public function reverseTransaction(
        string $transactionId,
        string $idempotencyKey,
        string $actorId,
        ?string $reason,
        string $ip,
    ): object {
        $correlationId = Str::uuid()->toString();

        $reversal = $this->reversalService->request(
            transactionId:  $transactionId,
            requestedBy:    $actorId,
            idempotencyKey: $idempotencyKey,
            reason:         $reason,
            correlationId:  $correlationId,
        );

        $this->recordAction(
            AdminActionType::TransactionReversed,
            $actorId,
            'transaction',
            $transactionId,
            $reason ?? 'admin reversal',
            $ip,
            ['reversal_id' => $reversal->id, 'idempotency_key' => $idempotencyKey],
        );

        $this->metrics->transactionAction('reverse');

        $this->audit->record(new AuditEventData(
            action:        'admin.transaction.reversed',
            actorType:     'ADMIN',
            resourceType:  'transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $transactionId,
            afterState:    ['reversal_id' => $reversal->id],
            ipAddress:     $ip,
        ));

        return $reversal;
    }

    public function refundTransaction(
        string $transactionId,
        string $idempotencyKey,
        string $actorId,
        ?string $reason,
        string $ip,
    ): TransactionProjectionModel {
        $correlationId = Str::uuid()->toString();

        $refund = $this->refundService->initiate(
            transactionId:  $transactionId,
            initiatedBy:    $actorId,
            idempotencyKey: $idempotencyKey,
            reason:         $reason,
            correlationId:  $correlationId,
        );

        $this->recordAction(
            AdminActionType::TransactionRefunded,
            $actorId,
            'transaction',
            $transactionId,
            $reason ?? 'admin refund',
            $ip,
            ['refund_id' => $refund->id, 'idempotency_key' => $idempotencyKey],
        );

        $this->metrics->transactionAction('refund');

        $this->audit->record(new AuditEventData(
            action:        'admin.transaction.refunded',
            actorType:     'ADMIN',
            resourceType:  'transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $transactionId,
            afterState:    ['refund_id' => $refund->id],
            ipAddress:     $ip,
        ));

        return $refund;
    }

    private function recordAction(
        AdminActionType $type,
        string $actorId,
        string $subjectType,
        string $subjectId,
        string $reason,
        string $ip,
        array $metadata = [],
    ): void {
        AdminAction::create([
            'id'           => (string) Uuid::uuid7(),
            'action_type'  => $type->value,
            'actor_id'     => $actorId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'metadata'     => array_merge($metadata, ['reason' => $reason]),
            'ip_address'   => $ip,
            'performed_at' => now(),
        ]);
    }
}
