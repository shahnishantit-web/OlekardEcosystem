<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KycCaseModel extends Model
{
    protected $connection = 'kyc';

    protected $table = 'kyc.kyc_cases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'         => KycCaseStatus::class,
        'attempt_number' => 'integer',
        'submitted_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'verified_at'    => 'datetime',
        'expires_at'     => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function personalInfo(): HasOne
    {
        return $this->hasOne(KycPersonalInfoModel::class, 'kyc_case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocumentModel::class, 'kyc_case_id')
            ->whereNull('deleted_at');
    }

    public function allDocuments(): HasMany
    {
        return $this->hasMany(KycDocumentModel::class, 'kyc_case_id');
    }

    public function verificationResults(): HasMany
    {
        return $this->hasMany(KycVerificationResultModel::class, 'kyc_case_id');
    }
}
