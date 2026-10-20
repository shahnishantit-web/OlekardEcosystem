<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Enums\NormalBalance;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Support\Facades\DB;

class LedgerBalanceService
{
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
    ) {}
    /**
     * Compute the settled balance for a ledger account.
     *
     * Returns 0 for unknown references (FR-E07-022 — no account registered = zero balance).
     * Uses direction-conditional raw SQL per research.md §4.
     */
    public function getBalance(string $ledgerAccountReference): int
    {
        $start   = microtime(true);
        $account = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $ledgerAccountReference)
            ->first(['id', 'normal_balance']);

        if ($account === null) {
            $this->metrics->histogram('ledger.balance.query.duration', (microtime(true) - $start) * 1000);
            return 0;
        }

        $normalBalance = $account->normal_balance instanceof \BackedEnum
            ? $account->normal_balance->value : (string) $account->normal_balance;

        if ($normalBalance === NormalBalance::Credit->value) {
            $row = DB::connection('platform')->selectOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN jl.direction = 'debit'  THEN jl.amount ELSE 0 END), 0)
                    AS balance
                 FROM ledger.journal_lines jl
                 JOIN ledger.journals       j  ON j.id  = jl.journal_id
                 WHERE jl.ledger_account_id = ?
                   AND j.status = 'posted'",
                [$account->id],
            );
        } else {
            $row = DB::connection('platform')->selectOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN jl.direction = 'debit'  THEN jl.amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END), 0)
                    AS balance
                 FROM ledger.journal_lines jl
                 JOIN ledger.journals       j  ON j.id  = jl.journal_id
                 WHERE jl.ledger_account_id = ?
                   AND j.status = 'posted'",
                [$account->id],
            );
        }

        $this->metrics->histogram('ledger.balance.query.duration', (microtime(true) - $start) * 1000);

        return (int) ($row->balance ?? 0);
    }

    /**
     * Check whether any posted journal references the given transaction reference.
     */
    public function entryExists(string $transactionReference): bool
    {
        return DB::connection('platform')
            ->table('ledger.journals')
            ->where('transaction_reference', $transactionReference)
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Return paginated account history (journal lines with journal context).
     *
     * @return array{data: list<array<string,mixed>>, meta: array{total:int,page:int,per_page:int,pages:int}}
     */
    public function getTransactionHistory(string $transactionReference): array
    {
        return DB::connection('platform')->table('ledger.journal_lines as line')
            ->join('ledger.journals as journal', 'journal.id', '=', 'line.journal_id')
            ->join('ledger.ledger_accounts as account', 'account.id', '=', 'line.ledger_account_id')
            ->where('journal.transaction_reference', $transactionReference)
            ->where('journal.status', 'posted')->orderBy('journal.posted_at')->orderBy('line.id')
            ->get(['line.id', 'journal.entry_type', 'line.direction', 'line.amount', 'line.currency_code',
                'account.ledger_account_reference', 'journal.posted_at'])
            ->map(fn ($line): array => (array) $line)->all();
    }

    public function getAccountHistory(string $ledgerAccountReference, int $page, int $perPage, array $filters = []): array
    {
        $account = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $ledgerAccountReference)
            ->first(['id']);

        if ($account === null) {
            return [
                'data' => [],
                'meta' => ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0],
            ];
        }

        $query = DB::connection('platform')
            ->table('ledger.journal_lines as jl')
            ->join('ledger.journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.ledger_account_id', $account->id)
            ->where('j.status', 'posted');
        if (! empty($filters['from'])) $query->where('j.posted_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->where('j.posted_at', '<=', $filters['to'].' 23:59:59');
        if (! empty($filters['entry_type'])) $query->where('j.entry_type', $filters['entry_type']);
        $total = (clone $query)->count();

        $rows = $query
            ->join('ledger.ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->where('jl.ledger_account_id', $account->id)
            ->where('j.status', 'posted')
            ->orderByDesc('j.posted_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'jl.id',
                'j.id as journal_id',
                'j.entry_type',
                'jl.direction',
                'jl.amount',
                'jl.currency_code',
                'j.transaction_reference',
                'j.description',
                'j.correlation_id',
                'j.posted_at',
            ]);

        $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

        return [
            'data' => $rows->map(fn ($row) => [
                'id'                    => $row->id,
                'journal_id'            => $row->journal_id,
                'entry_type'            => $row->entry_type,
                'direction'             => $row->direction,
                'amount'                => (int) $row->amount,
                'currency_code'         => $row->currency_code,
                'transaction_reference' => $row->transaction_reference,
                'description'           => $row->description,
                'correlation_id'        => $row->correlation_id,
                'posted_at'             => $row->posted_at,
            ])->all(),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $pages,
            ],
        ];
    }
}
