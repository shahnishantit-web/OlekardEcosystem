<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Admin\Domain\Enums\AdminActionType;
use App\Modules\Admin\Domain\Models\AdminAction;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\FreezeAccountCommand;
use App\Modules\Risk\Application\Services\AccountHoldService;
use App\Modules\Wallet\Application\Services\WalletBalanceService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class WalletAdminService
{
    public function __construct(
        private readonly AccountHoldService    $holdService,
        private readonly AuditServiceInterface $audit,
        private readonly AdminMetricsInterface $metrics,
        private readonly WalletBalanceService $balances,
        private readonly LedgerModuleContract $ledger,
    ) {}

    public function searchWallets(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::connection('wallet')->table('wallet.wallets as w')
            ->leftJoin('identity.users as u', 'u.id', '=', 'w.owner_id')
            ->select([
                'w.id', 'w.owner_id', 'w.status',
                'w.created_at', 'u.email as owner_email',
            ])->selectSub(function ($query): void {
                $query->from('wallet.currency_pockets')->select('currency_code')
                    ->whereColumn('wallet_id', 'w.id')->where('status', 'active')->orderBy('currency_code')->limit(1);
            }, 'currency');

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($sub) use ($q): void {
                $sub->where('w.id::text', 'ilike', "%{$q}%")
                    ->orWhere('u.email', 'ilike', "%{$q}%")
                    ->orWhereRaw("EXISTS (
                        SELECT 1 FROM profile.user_profiles p
                        WHERE p.user_id = w.owner_id
                        AND (p.mobile_number ILIKE ? OR p.first_name ILIKE ? OR p.last_name ILIKE ?)
                    )", ["%{$q}%", "%{$q}%", "%{$q}%"]);
            });
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('w.status', $filters['status']);
        }

        return $query->orderBy('w.created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    public function getWallet(string $walletId): array
    {
        $wallet = DB::connection('wallet')->table('wallet.wallets as w')
            ->leftJoin('identity.users as u', 'u.id', '=', 'w.owner_id')
            ->where('w.id', $walletId)
            ->select(['w.*', 'u.email as owner_email'])
            ->first();

        if ($wallet === null) {
            throw new \DomainException('WALLET_NOT_FOUND');
        }

        // Get balance from pockets
        $pockets = DB::connection('wallet')->table('wallet.currency_pockets')
            ->where('wallet_id', $walletId)
            ->get(['currency_code', 'ledger_account_reference'])
            ->map(function ($pocket) use ($walletId): array {
                $balance = $this->balances->getBalance($walletId, $pocket->currency_code, (string) Uuid::uuid7());
                return (array) $pocket + ['available_balance' => (string) $balance['available_balance']];
            })->all();

        // Get active hold if any
        $hold = DB::connection('risk')->table('risk.account_holds')
            ->where('subject_type', 'wallet')
            ->where('subject_id', $walletId)
            ->where('status', 'active')
            ->first(['id', 'hold_type', 'placed_by', 'placed_at']);

        return [
            'id'               => $wallet->id,
            'owner_id'         => $wallet->owner_id,
            'owner_email'      => $wallet->owner_email,
            'status'           => $wallet->status,
            'currency'         => $pockets[0]['currency_code'] ?? null,
            'created_at'       => $wallet->created_at,
            'pockets'          => array_map(fn ($p) => (array) $p, $pockets),
            'account_hold'     => $hold ? (array) $hold : null,
        ];
    }

    public function getWalletLedger(string $walletId, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $pocketRef = DB::connection('wallet')->table('wallet.currency_pockets')
            ->where('wallet_id', $walletId)
            ->where('currency_code', $filters['currency'] ?? 'BWP')
            ->value('ledger_account_reference');

        if ($pocketRef === null) {
            throw new \DomainException('WALLET_NOT_FOUND');
        }

        $history = $this->ledger->getAccountHistory($pocketRef, $page, $perPage, $filters);
        return new LengthAwarePaginator($history['data'], $history['meta']['total'], $perPage, $page);
    }

    public function freezeWallet(
        string $walletId,
        string $reason,
        string $makerId,
        string $checkerId,
        string $holdType,
        string $ip,
    ): object {
        // Verify wallet exists before any further checks
        $walletExists = DB::connection('wallet')->table('wallet.wallets')
            ->where('id', $walletId)
            ->exists();

        if (! $walletExists) {
            throw new \DomainException('WALLET_NOT_FOUND');
        }

        if ($makerId === $checkerId) {
            throw new \DomainException('MAKER_CHECKER_VIOLATION');
        }

        // Check if already frozen
        $existing = DB::connection('risk')->table('risk.account_holds')
            ->where('subject_type', 'wallet')
            ->where('subject_id', $walletId)
            ->where('status', 'active')
            ->first(['id']);

        if ($existing !== null) {
            throw new \DomainException('WALLET_ALREADY_FROZEN');
        }

        $cmd  = new FreezeAccountCommand(
            subjectType: 'wallet',
            subjectId:   $walletId,
            reason:      $reason,
            placedBy:    $makerId,
            checkerId:   $checkerId,
            walletId:    $walletId,
        );

        $hold = $this->holdService->placeHold($cmd);

        $this->recordAction(AdminActionType::FreezeWallet, $makerId, 'wallet', $walletId, $reason, $ip, [
            'hold_id'   => $hold->id,
            'hold_type' => $holdType,
            'checker_id' => $checkerId,
        ]);

        $this->metrics->walletAction('freeze_wallet');
        $this->audit->record(new AuditEventData(
            action:        'admin.wallet.frozen',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $makerId,
            resourceId:    $walletId,
            afterState:    ['status' => 'frozen', 'hold_id' => $hold->id],
            ipAddress:     $ip,
        ));

        return $hold;
    }

    public function unfreezeWallet(string $walletId, string $reason, string $actorId, string $ip): void
    {
        $hold = DB::connection('risk')->table('risk.account_holds')
            ->where('subject_type', 'wallet')
            ->where('subject_id', $walletId)
            ->where('status', 'active')
            ->first(['id']);

        if ($hold === null) {
            throw new \DomainException('WALLET_NOT_FROZEN');
        }

        $this->holdService->liftHold($hold->id, $actorId, $reason);

        $this->recordAction(AdminActionType::UnfreezeWallet, $actorId, 'wallet', $walletId, $reason, $ip);
        $this->metrics->walletAction('unfreeze_wallet');

        $this->audit->record(new AuditEventData(
            action:        'admin.wallet.unfrozen',
            actorType:     'ADMIN',
            resourceType:  'wallet',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $walletId,
            afterState:    ['status' => 'active'],
            ipAddress:     $ip,
        ));
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
            'metadata'     => $metadata,
            'reason'       => $reason,
            'ip_address'   => $ip,
            'performed_at' => now(),
        ]);
    }
}
