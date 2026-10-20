<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Infrastructure\Http\Resources\AdminTransactionResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TransactionController
{
    private const REVERSIBLE_TYPES = ['wallet_transfer', 'agent_cash_in', 'agent_cash_out'];

    public function __construct(
        private readonly PendingAdminActionService $pendingActions,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('platform')->table('transaction.transaction_projections as t')
            ->leftJoin('identity.users as u', 't.customer_id', '=', 'u.id')
            ->leftJoin('profile.user_profiles as profile', 'profile.user_id', '=', 't.customer_id')
            ->select([
                't.id as transaction_id',
                't.id',
                't.reference as reference_number',
                't.type',
                't.status',
                't.amount as amount_minor_units',
                't.currency_code',
                DB::raw('0 as fee_minor_units'),
                't.customer_id as initiator_user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', profile.first_name, profile.last_name)), ''), '') as initiator_name"),
                't.wallet_id as source_wallet_id',
                DB::raw('NULL as destination_wallet_id'),
                't.wallet_id',
                't.direction',
                't.created_at',
                't.completed_at',
                DB::raw('NULL as reversal_id'),
                't.idempotency_key',
            ]);

        if ($userId = $request->query('user_id')) {
            $query->where('t.customer_id', $userId);
        }

        if ($q = $request->query('q')) {
            $query->where(function ($sub) use ($q): void {
                $sub->where('t.reference', 'ilike', "%{$q}%")
                    ->orWhereRaw("concat_ws(' ', profile.first_name, profile.last_name) ilike ?", ["%{$q}%"])
                    ->orWhere('profile.mobile_number', 'ilike', "%{$q}%")
                    ->orWhere('u.email', 'ilike', "%{$q}%");
            });
        }

        if ($type = $request->query('type')) {
            $query->where('t.type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('t.status', $status);
        }

        if ($from = $request->query('from_date')) {
            $query->where('t.created_at', '>=', $from);
        }

        if ($to = $request->query('to_date')) {
            $query->where('t.created_at', '<=', $to . ' 23:59:59');
        }

        if ($min = $request->query('min_amount')) {
            $query->where('t.amount', '>=', (int) $min);
        }

        if ($max = $request->query('max_amount')) {
            $query->where('t.amount', '<=', (int) $max);
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('t.created_at')->paginate($perPage, ['*'], 'page', $page);

        $data = $paginated->getCollection()->map(fn ($t): array => AdminTransactionResource::format($t));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $transactionId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $actorId       = (string) $request->attributes->get('identity.user_id');

        $txn = DB::connection('platform')->table('transaction.transaction_projections as t')
            ->leftJoin('profile.user_profiles as profile', 'profile.user_id', '=', 't.customer_id')
            ->where('t.id', $transactionId)
            ->select([
                't.id as transaction_id',
                't.id',
                't.reference as reference_number',
                't.type',
                't.status',
                't.amount as amount_minor_units',
                't.currency_code',
                DB::raw('0 as fee_minor_units'),
                't.customer_id as initiator_user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', profile.first_name, profile.last_name)), ''), '') as initiator_name"),
                't.wallet_id as source_wallet_id',
                DB::raw('NULL as destination_wallet_id'),
                't.created_at',
                't.completed_at',
                't.idempotency_key',
                't.ledger_journal_id',
            ])
            ->first();

        if ($txn === null) {
            return ErrorResource::make('ADMINAPP_TXN_001', 'Transaction not found.', $correlationId, 404);
        }

        $journalIds = DB::connection('ledger')->table('ledger.journals')
            ->where(function ($query) use ($txn): void {
                if (!empty($txn->ledger_journal_id)) {
                    $query->where('id', $txn->ledger_journal_id)
                        ->orWhere('transaction_reference', $txn->reference_number);
                } else {
                    $query->where('transaction_reference', $txn->reference_number);
                }
            })
            ->pluck('id');

        $ledgerEntries = DB::connection('ledger')->table('ledger.journal_lines as line')
            ->join('ledger.ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->join('ledger.journals as journal', 'journal.id', '=', 'line.journal_id')
            ->whereIn('line.journal_id', $journalIds)
            ->orderBy('journal.posted_at')
            ->orderBy('line.created_at')
            ->get([
                'line.id as entry_id',
                'account.ledger_account_reference as ledger_account',
                'line.amount as amount_minor_units',
                'line.direction',
                'journal.posted_at',
            ])
            ->toArray();

        $riskAssessment = DB::connection('risk')->table('risk.risk_evaluations')
            ->where('transaction_reference', $txn->reference_number)
            ->orderByDesc('evaluated_at')
            ->first(['overall_score', 'risk_level', 'rules_applied', 'evaluated_at']);

        $this->audit->record(new AuditEventData(
            action:        'adminapp.transaction.investigated',
            actorType:     'USER',
            resourceType:  'Transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $transactionId,
        ));

        $formatted = AdminTransactionResource::format($txn);
        $formatted['ledger_entries'] = $ledgerEntries;
        $formatted['fraud_assessment'] = $riskAssessment ? [
            'risk_score'  => (float) $riskAssessment->overall_score,
            'flags'       => array_values(array_filter(array_map(
                static fn ($rule): ?string => is_string($rule) ? $rule : (is_array($rule) ? ($rule['code'] ?? $rule['name'] ?? null) : null),
                is_array($riskAssessment->rules_applied) ? $riskAssessment->rules_applied : (json_decode((string) $riskAssessment->rules_applied, true) ?: []),
            ))),
            'assessed_at' => $riskAssessment->evaluated_at,
        ] : null;

        return response()->json(['data' => $formatted]);
    }

    public function reverse(Request $request, string $transactionId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->user()?->id;
        $reason         = $request->input('reason', '');

        $txn = DB::table('transactions')->where('id', $transactionId)->first(['id', 'type', 'status', 'reversal_id']);

        if ($txn === null) {
            return ErrorResource::make('ADMINAPP_TXN_001', 'Transaction not found.', $correlationId, 404);
        }

        if (!in_array($txn->type, self::REVERSIBLE_TYPES, true)) {
            return ErrorResource::make(
                'ADMINAPP_015',
                "Transaction type '{$txn->type}' is not eligible for admin reversal. Only wallet_transfer, agent_cash_in, and agent_cash_out transactions can be reversed.",
                $correlationId,
                422,
            );
        }

        if ($txn->reversal_id !== null || $txn->status === 'reversed') {
            return ErrorResource::make('ADMINAPP_TXN_002', 'Transaction has already been reversed.', $correlationId, 422);
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.transaction.reverse',
            resourceType:   'Transaction',
            resourceId:     $transactionId,
            payload:        ['reason' => $reason],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.transaction.reverse.requested',
            actorType:     'USER',
            resourceType:  'Transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $transactionId,
            afterState:    ['approval_id' => $pendingAction->id, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.transaction.reverse',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }
}
