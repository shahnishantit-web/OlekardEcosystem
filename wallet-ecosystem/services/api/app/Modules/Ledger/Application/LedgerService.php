<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Application\Services\LedgerAccountService;
use App\Modules\Ledger\Application\Services\LedgerBalanceService;
use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Domain\Enums\JournalEntryType;

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
}
