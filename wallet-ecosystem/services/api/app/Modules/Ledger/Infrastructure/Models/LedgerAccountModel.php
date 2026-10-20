<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Domain\Enums\NormalBalance;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ledger_account_reference
 * @property string $owner_reference
 * @property \App\Modules\Ledger\Domain\Enums\AccountType $account_type
 * @property \App\Modules\Ledger\Domain\Enums\NormalBalance $normal_balance
 * @property \App\Modules\Ledger\Domain\Enums\LedgerAccountStatus $status
 * @property string $currency_code
 * @property int $balance_debit
 * @property int $balance_credit
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class LedgerAccountModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'ledger.ledger_accounts';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'account_type'   => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'status'         => LedgerAccountStatus::class,
    ];
}
