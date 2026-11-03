<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Application\Services\LedgerAccountService;
use App\Modules\Ledger\Application\Services\LedgerBalanceService;
use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Domain\Enums\JournalEntryType;
use App\Modules\Ledger\Domain\Enums\JournalStatus;
use App\Modules\Ledger\Domain\ValueObjects\PostedJournalEvidence;
use App\Modules\Ledger\Infrastructure\Models\JournalModel;

/**
 * Concrete adapter that fulfils LedgerModuleContract by delegating to domain services.
 * Bound in LedgerServiceProvider once Phase 9 (T062) completes.
 */
final class LedgerService implements LedgerModuleContract
{
    public function __construct(
        private readonly LedgerPostingService $postingService,
        private readonly LedgerBalanceService $balanceService,
        private readonly LedgerAccountService $accountService,
    ) {}

    public function postEntry(array $request): string
    {
        $entryType = $request['entry_type'] ?? 'debit';
        $actorId   = $request['actor_id'] ?? null;
        $actorId   = ($actorId === null || $actorId === '') ? '00000000-0000-0000-0000-000000000000' : $actorId;

        if ($entryType === JournalEntryType::Reversal->value) {
            throw new \InvalidArgumentException('entry_type "reversal" is internal only; use the reverse endpoint.');
        }

        return $this->postingService->post($request, $actorId, $entryType);
    }

    public function entryExists(string $transactionReference): bool
    {
        return $this->balanceService->entryExists($transactionReference);
    }

    public function getBalance(string $ledgerAccountReference): int
    {
        return $this->balanceService->getBalance($ledgerAccountReference);
    }

    public function registerAccount(
        string $ledgerAccountReference,
        string $accountType,
        string $currencyCode,
        string $ownerReference,
        string $ownerType
    ): void {
        $this->accountService->registerAccount(
            ref:           $ledgerAccountReference,
            accountType:   $accountType,
            currencyCode:  $currencyCode,
            ownerRef:      $ownerReference,
            ownerType:     $ownerType,
            actorId:       (string) (request()->attributes->get('identity.user_id') ?: $ownerReference),
            correlationId: (string) (request()->header('X-Correlation-Id') ?: \Illuminate\Support\Str::uuid7()),
        );
    }

    public function getAccountHistory(
        string $ledgerAccountReference,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        return $this->balanceService->getAccountHistory($ledgerAccountReference, $page, $perPage, $filters);
    }

    public function getTransactionHistory(string $transactionReference): array
    {
        return $this->balanceService->getTransactionHistory($transactionReference);
    }

    public function findPostedJournalEvidence(string $idempotencyKey): ?PostedJournalEvidence
    {
        $journal = JournalModel::query()->with(['lines.account'])
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($journal === null || $journal->status !== JournalStatus::Posted || $journal->posted_at === null) {
            return null;
        }

        $lines = [];
        foreach ($journal->lines as $line) {
            if ($line->account === null) return null;
            $lines[] = [
                'account_reference' => $line->account->ledger_account_reference,
                'direction' => $line->direction->value,
                'amount_minor' => (int) $line->amount,
                'currency_code' => $line->currency_code,
            ];
        }

        return new PostedJournalEvidence(
            journalId: $journal->id,
            status: $journal->status->value,
            entryType: $journal->entry_type->value,
            transactionReference: (string) $journal->transaction_reference,
            idempotencyKey: $journal->idempotency_key,
            currencyCode: $journal->currency_code,
            lines: $lines,
        );
    }
}
