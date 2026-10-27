<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Infrastructure\Models\JournalLineModel;
use App\Modules\Ledger\Infrastructure\Models\JournalModel;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use Illuminate\Support\Facades\DB;

/**
 * P21 T004: Read-only service for retrieving ledger journal summaries by reference.
 *
 * Owned by the Ledger module. Called by Payment admin controllers to show
 * journal context for an intent without crossing the domain boundary directly.
 *
 * Rules:
 * - Returns only journal IDs, posted_at, description, and line items.
 * - Line items use account_type (human-readable) not raw UUID labels.
 * - No mutation is performed.
 */
final class LedgerJournalQueryService
{
    /**
     * Retrieve journal summaries whose transaction_reference equals the given reference.
     *
     * @return array<int, array{journal_id: string, posted_at: string, description: string, lines: array<int, array{type: string, account_name: string, amount_minor: int, currency_code: string}>, audit_link: string|null}>
     */
    public function forReference(string $reference): array
    {
        $journals = JournalModel::where('transaction_reference', $reference)
            ->orderBy('posted_at', 'asc')
            ->get();

        return $journals->map(function (JournalModel $journal): array {
            $lines = $journal->lines()->get()->map(function (JournalLineModel $line) use ($journal): array {
                $account = LedgerAccountModel::find($line->ledger_account_id);
                $accountName = $account
                    ? $this->accountDisplayName($account)
                    : 'Unknown Account';

                return [
                    'type'         => $line->direction->value === 'debit' ? 'debit' : 'credit',
                    'account_name' => $accountName,
                    'amount_minor' => $line->amount,
                    'currency_code' => $line->currency_code ?? $journal->currency_code,
                ];
            })->all();

            return [
                'journal_id' => $journal->id,
                'posted_at'  => $journal->posted_at?->toIso8601String() ?? $journal->created_at?->toIso8601String() ?? '',
                'description' => $this->buildDescription($journal),
                'lines'      => $lines,
                'audit_link' => null,
            ];
        })->all();
    }

    private function accountDisplayName(LedgerAccountModel $account): string
    {
        $typeLabel = match (true) {
            str_contains($account->ledger_account_reference, 'wallet') => 'Customer Wallet',
            str_contains($account->ledger_account_reference, 'provider') => 'Provider Settlement',
            str_contains($account->ledger_account_reference, 'fee') => 'Fee Account',
            str_contains($account->ledger_account_reference, 'float') => 'Agent Float',
            default => ucwords(str_replace('_', ' ', $account->account_type->value ?? 'account')),
        };

        return $typeLabel;
    }

    private function buildDescription(JournalModel $journal): string
    {
        $entryType = $journal->entry_type?->value ?? 'entry';
        $currency  = $journal->currency_code;

        $totalDebit = $journal->lines()->where('direction', 'debit')->sum('amount');

        $amountFormatted = number_format($totalDebit / 100, 2);

        return ucwords(str_replace('_', ' ', $entryType)) . " — {$currency} {$amountFormatted}";
    }
}
