<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerificationResultModel extends Model
{
    protected $connection = 'kyc';

    protected $table = 'kyc.kyc_verification_results';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    // raw_response_hash is an internal security field — never exposed in API responses
    protected $hidden = ['raw_response_hash'];

    protected $casts = [
        'outcome'          => KycVerificationOutcome::class,
        'confidence_score' => 'float',
        'created_at'       => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('KycVerificationResultModel is immutable — updates are not permitted.');
        }
        return parent::save($options);
    }

    public function kycCase(): BelongsTo
    {
        return $this->belongsTo(KycCaseModel::class, 'kyc_case_id');
    }
}
