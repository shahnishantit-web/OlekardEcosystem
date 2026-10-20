<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string  $id
 * @property string  $funding_transaction_id
 * @property string  $bank_notification_id
 * @property string  $bank_reference_code
 * @property int     $matched_amount
 * @property string  $currency_code
 * @property Carbon  $reconciled_at
 * @property Carbon  $created_at
 * @property Carbon  $updated_at
 */
class BankReconciliationModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'funding.bank_reconciliations';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'reconciled_at' => 'datetime',
    ];
}
