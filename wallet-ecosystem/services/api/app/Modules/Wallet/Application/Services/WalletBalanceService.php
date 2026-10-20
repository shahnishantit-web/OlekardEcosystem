<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application\Services;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Wallet\Domain\Exceptions\WalletNotFoundException;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentCurrencyPocketRepository;
use App\Modules\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Illuminate\Support\Facades\Log;

final class WalletBalanceService
{
    public function __construct(
        private readonly EloquentWalletRepository         $walletRepository,
        private readonly EloquentCurrencyPocketRepository $pocketRepository,
        private readonly LedgerModuleContract             $ledgerModule,
        private readonly MetricsCollectorInterface        $metrics,
    ) {}

    /**
     * Compute the balance for a specific pocket.
     *
     * Returns an array containing:
     *   wallet_id, currency_code, precision, ledger_balance, held_amount,
     *   available_balance, pending_balance (0 until E11), wallet_status, as_of_timestamp,
     *   holds (array of active holds), meta (optional ledger stub flag)
     *
     * @throws WalletNotFoundException if wallet or pocket not found
     */
    public function getBalance(string $walletId, string $currencyCode, string $correlationId): array
    {
        $asOfTimestamp = now()->toRfc3339String();

        $wallet = $this->walletRepository->findById($walletId);
        if ($wallet === null) {
            throw new WalletNotFoundException($walletId);
        }

        $pocket = $this->pocketRepository->findByWalletAndCurrency($walletId, strtoupper($currencyCode));
        if ($pocket === null) {
            throw new WalletNotFoundException("{$walletId}/{$currencyCode}");
        }

        $meta = [];

        // Fetch ledger balance from E07 (with stub fallback for pre-E07 environments)
        try {
            $ledgerBalance = $this->ledgerModule->getBalance($pocket->ledger_account_reference);
        } catch (ModuleNotImplementedException) {
            if (config('wallet.stub_ledger_balance', false)) {
                $ledgerBalance     = 0;
                $meta['ledger_unavailable'] = true;
            } else {
                throw new \RuntimeException('Ledger service unavailable.', 503);
            }
        }

        // Compute held_amount from active holds
        $activeHolds  = $pocket->activeHolds()->get();
        $heldAmount   = (int) $activeHolds->sum('amount');

        // Constitution Principle 4: available_balance MUST NOT be negative
        $availableBalance = max(0, $ledgerBalance - $heldAmount);

        if ($availableBalance < 0) {
            // Defensive invariant guard — should never happen given max(0,...) above
            throw new \RuntimeException("Available balance invariant violated: wallet={$walletId}, pocket={$pocket->id}");
        }

        $currency  = \App\Modules\Wallet\Infrastructure\Models\CurrencyModel::on('wallet')->find(strtoupper($currencyCode));
        $precision = $currency?->precision;

        $holdsData = $activeHolds->map(fn ($h) => [
            'hold_id'     => $h->id,
            'amount'      => $h->amount,
            'created_at'  => $h->created_at?->toIso8601String(),
            'expires_at'  => $h->expires_at?->toIso8601String(),
            'description' => $h->description,
        ])->all();

        $pendingMeta = config('wallet.show_pending_meta', false)
            ? ['pending_balance_note' => 'Pending balance reserved for E11 TransactionServiceContract integration; returns 0 until E11 is implemented.']
            : [];

        return array_merge([
            'wallet_id'         => $walletId,
            'currency_code'     => strtoupper($currencyCode),
            'precision'         => $precision,
            'ledger_balance'    => $ledgerBalance,
            'held_amount'       => $heldAmount,
            'available_balance' => $availableBalance,
            'pending_balance'   => 0,
            'wallet_status'     => $wallet->status instanceof \BackedEnum ? $wallet->status->value : $wallet->status,
            'as_of_timestamp'   => $asOfTimestamp,
            'holds'             => $holdsData,
        ], $meta ? ['meta' => array_merge($meta, $pendingMeta)] : ($pendingMeta ? ['meta' => $pendingMeta] : []));
    }

    /**
     * Compute balance assuming the caller already holds a SELECT FOR UPDATE lock on the pocket row.
     * Used internally by WalletHoldService::place() to avoid a double-query under lock.
     *
     * @return array{ledger_balance: int, held_amount: int, available_balance: int}
     */
    public function computeLockedBalance(CurrencyPocketModel $pocket): array
    {
        try {
            $ledgerBalance = $this->ledgerModule->getBalance($pocket->ledger_account_reference);
        } catch (ModuleNotImplementedException) {
            $ledgerBalance = config('wallet.stub_ledger_balance', false) ? 0 : throw new \RuntimeException('Ledger service unavailable.', 503);
        }

        $heldAmount = (int) \App\Modules\Wallet\Infrastructure\Models\WalletHoldModel::on('wallet')
            ->where('pocket_id', $pocket->id)
            ->where('status', 'active')
            ->sum('amount');

        $available = max(0, $ledgerBalance - $heldAmount);

        return [
            'ledger_balance'    => $ledgerBalance,
            'held_amount'       => $heldAmount,
            'available_balance' => $available,
        ];
    }

    public function availableBalance(string $walletId, string $currencyCode): ?int
    {
        try {
            $balance = $this->getBalance($walletId, $currencyCode, '');
            return $balance['available_balance'];
        } catch (WalletNotFoundException) {
            return null;
        }
    }
}
