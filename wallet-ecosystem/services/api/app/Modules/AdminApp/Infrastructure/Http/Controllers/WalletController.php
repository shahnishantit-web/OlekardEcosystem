<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Infrastructure\Http\Resources\AdminWalletResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WalletController
{
    public function __construct(
        private readonly PendingAdminActionService $pendingActions,
        private readonly AuditServiceInterface $audit,
        private readonly WalletBalanceService $balances,
        private readonly LedgerModuleContract $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('wallet')->table('wallet.wallets as w')
            ->leftJoin('wallet.currency_pockets as p', function ($join): void {
                $join->on('p.wallet_id', '=', 'w.id')->where('p.status', 'active');
            })
            ->leftJoin('identity.users as u', 'w.owner_id', '=', 'u.id')
            ->leftJoin('profile.user_profiles as profile', 'profile.user_id', '=', 'w.owner_id')
            ->select([
                'w.id as wallet_id',
                'w.wallet_reference as wallet_number',
                'w.owner_id as owner_user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', profile.first_name, profile.last_name)), ''), '') as owner_name"),
                DB::raw("'customer' as wallet_type"),
                'w.status',
                'p.currency_code',
                'w.created_at',
                DB::raw('NULL as frozen_at'),
                DB::raw('NULL as frozen_by'),
                DB::raw('NULL as freeze_reason'),
            ]);

        if ($userId = $request->query('user_id')) {
            $query->where('w.owner_id', $userId);
        }

        if ($q = $request->query('q')) {
            $query->where(function ($sub) use ($q): void {
                $sub->where('w.wallet_reference', 'ilike', "%{$q}%")
                    ->orWhereRaw("concat_ws(' ', profile.first_name, profile.last_name) ilike ?", ["%{$q}%"])
                    ->orWhere('profile.mobile_number', 'ilike', "%{$q}%")
                    ->orWhere('u.email', 'ilike', "%{$q}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('w.status', $status);
        }

        if ($walletType = $request->query('wallet_type')) {
            if ($walletType !== 'customer') {
                $query->whereRaw('1 = 0');
            }
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $data = $paginated->getCollection()->map(function ($wallet): array {
            $row = (array) $wallet;
            $row['balance_minor_units'] = 0;
            if (!empty($wallet->currency_code)) {
                $balance = $this->balances->getBalance(
                    (string) $wallet->wallet_id,
                    (string) $wallet->currency_code,
                    (string) Uuid::uuid4(),
                );
                $row['balance_minor_units'] = $balance['available_balance'];
            }
            return AdminWalletResource::format((object) $row);
        });

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

    public function show(Request $request, string $walletId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $wallet = DB::connection('wallet')->table('wallet.wallets')
            ->where('id', $walletId)->first();

        if ($wallet === null) {
            return ErrorResource::make('ADMINAPP_WALLET_001', 'Wallet not found.', $correlationId, 404);
        }

        $profile = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', $wallet->owner_id)->first(['first_name', 'last_name']);
        $pocket = DB::connection('wallet')->table('wallet.currency_pockets')
            ->where('wallet_id', $walletId)->where('status', 'active')->orderBy('currency_code')->first(['currency_code']);
        $currency = $pocket?->currency_code ?? 'BWP';
        $balance = $pocket === null ? 0 : $this->balances->getBalance($walletId, $currency, $correlationId)['available_balance'];
        $name = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));

        return response()->json(['data' => AdminWalletResource::format((object) [
            'id' => $wallet->id,
            'wallet_id' => $wallet->id,
            'wallet_number' => $wallet->wallet_reference,
            'owner_id' => $wallet->owner_id,
            'owner_user_id' => $wallet->owner_id,
            'owner_name' => $name,
            'wallet_type' => 'customer',
            'status' => $wallet->status,
            'currency_code' => $currency,
            'balance_minor_units' => $balance,
            'created_at' => $wallet->created_at,
            'frozen_at' => null,
            'frozen_by' => null,
            'freeze_reason' => null,
        ])]);
    }

    public function ledger(Request $request, string $walletId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $exists = DB::connection('wallet')->table('wallet.wallets')->where('id', $walletId)->exists();
        if (! $exists) {
            return ErrorResource::make('ADMINAPP_WALLET_001', 'Wallet not found.', $correlationId, 404);
        }

        $currency = strtoupper((string) $request->query('currency', 'BWP'));
        $accountReference = DB::connection('wallet')->table('wallet.currency_pockets')
            ->where('wallet_id', $walletId)->where('currency_code', $currency)->where('status', 'active')
            ->value('ledger_account_reference');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $history = $accountReference === null
            ? ['data' => [], 'meta' => ['total' => 0]]
            : $this->ledger->getAccountHistory((string) $accountReference, $page, $perPage);
        $rows = array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'type' => $entry['entry_type'],
            'direction' => $entry['direction'],
            'amount_minor_units' => (int) $entry['amount'],
            'running_balance_minor_units' => null,
            'reference' => $entry['transaction_reference'],
            'posted_at' => $entry['posted_at'],
        ], $history['data']);
        $total = (int) ($history['meta']['total'] ?? count($rows));

        return response()->json(['data' => [
            'data' => $rows,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]]);
    }

    public function freeze(Request $request, string $walletId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $reason         = $request->input('reason', '');

        $wallet = DB::connection('wallet')->table('wallet.wallets')->where('id', $walletId)->first(['id', 'status']);

        if ($wallet === null) {
            return ErrorResource::make('ADMINAPP_WALLET_001', 'Wallet not found.', $correlationId, 404);
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.wallet.freeze',
            resourceType:   'Wallet',
            resourceId:     $walletId,
            payload:        ['reason' => $reason],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.wallet.freeze.requested',
            actorType:     'USER',
            resourceType:  'Wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['approval_id' => $pendingAction->id, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.wallet.freeze',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }

    public function unfreeze(Request $request, string $walletId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $reason         = $request->input('reason', '');

        $wallet = DB::connection('wallet')->table('wallet.wallets')->where('id', $walletId)->first(['id', 'status']);

        if ($wallet === null) {
            return ErrorResource::make('ADMINAPP_WALLET_001', 'Wallet not found.', $correlationId, 404);
        }

        if ($wallet->status !== 'frozen') {
            return ErrorResource::make('ADMINAPP_WALLET_002', 'Wallet is not currently frozen.', $correlationId, 422);
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.wallet.unfreeze',
            resourceType:   'Wallet',
            resourceId:     $walletId,
            payload:        ['reason' => $reason],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.wallet.unfreeze.requested',
            actorType:     'USER',
            resourceType:  'Wallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['approval_id' => $pendingAction->id, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.wallet.unfreeze',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }
}
