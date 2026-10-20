<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Repositories;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use Ramsey\Uuid\Uuid;

class EloquentLedgerAccountRepository
{
    public function findByReference(string $ref): ?LedgerAccountModel
    {
        return LedgerAccountModel::where('ledger_account_reference', $ref)->first();
    }

    public function create(array $attributes): LedgerAccountModel
    {
        return LedgerAccountModel::create($attributes);
    }

    /**
     * Find an account by reference, or auto-register it with provisional liability defaults.
     *
     * Auto-registration applies when postEntry() is called for an account that has not been
     * explicitly registered via registerAccount(). Provisional defaults per FR-E07-003 and
     * TBD-E07-002: account_type = liability, normal_balance = credit, owner_reference = NULL.
     */
    public function findOrAutoRegister(string $ref, string $currencyCode, string $createdBy): LedgerAccountModel
    {
        $account = $this->findByReference($ref);

        if ($account !== null) {
            return $account;
        }

        $accountType = AccountType::Liability;

        return $this->create([
            'id'                        => (string) Uuid::uuid7(),
            'ledger_account_reference'  => $ref,
            'account_type'              => $accountType->value,
            'normal_balance'            => $accountType->normalBalance()->value,
            'currency_code'             => $currencyCode,
            'owner_reference'           => null,
            'owner_type'                => null,
            'status'                    => LedgerAccountStatus::Active->value,
            'created_by'                => $createdBy,
        ]);
    }
}
