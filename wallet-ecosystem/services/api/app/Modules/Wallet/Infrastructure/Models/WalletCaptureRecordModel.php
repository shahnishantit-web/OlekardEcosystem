<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable, Wallet-owned audit/evidence row for one committed capture.
 *
 * @property string $id
 * @property string $hold_id
 * @property string $owner_actor_id
 * @property string $payment_attempt_id
 * @property string $payment_intent_id
 * @property string $organisation_id
 * @property string $currency_code
 * @property int $amount_minor
 * @property string $ledger_journal_id
 * @property string $ledger_idempotency_key
 * @property string $debit_account_reference
 * @property string $credit_account_reference
 * @property string $correlation_id
 * @property Carbon $captured_at
 */
final class WalletCaptureRecordModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.wallet_capture_records';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'captured_at' => 'datetime',
    ];
}
