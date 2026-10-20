<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycPersonalInfoModel extends Model
{
    protected $connection = 'kyc';

    protected $table = 'kyc.kyc_personal_info';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    // national_id_hash is never returned in API responses — hidden at model layer
    protected $hidden = ['national_id_hash'];

    protected $casts = [
        'date_of_birth' => 'encrypted', // stored as AES-256-CBC ciphertext (E26 FR-008)
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function kycCase(): BelongsTo
    {
        return $this->belongsTo(KycCaseModel::class, 'kyc_case_id');
    }
}
