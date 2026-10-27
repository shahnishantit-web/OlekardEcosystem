<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                             $id
 * @property string                             $organisation_id
 * @property string                             $account_type
 * @property string                             $account_number
 * @property string|null                        $bank_code
 * @property string|null                        $bank_name
 * @property string                             $account_holder_name
 * @property string                             $currency_code
 * @property PayoutBeneficiaryStatus            $status
 * @property \Illuminate\Support\Carbon|null    $verified_at
 * @property string|null                        $verification_reference
 * @property string                             $created_by
 */
final class PayoutBeneficiaryModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payout_beneficiaries';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'organisation_id',
        'account_type',
        'account_number',
        'bank_code',
        'bank_name',
        'account_holder_name',
        'currency_code',
        'status',
        'verified_at',
        'verification_reference',
        'created_by',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'status'         => PayoutBeneficiaryStatus::class,
            'verified_at'    => 'immutable_datetime',
            'created_at'     => 'immutable_datetime',
            'updated_at'     => 'immutable_datetime',
        ];
    }

    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequestModel::class, 'beneficiary_id');
    }
}
