<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Models;

use App\Modules\Kyc\Domain\Enums\KycDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KycDocumentModel extends Model
{
    use SoftDeletes;

    protected $connection = 'kyc';

    protected $table = 'kyc.kyc_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    // Override SoftDeletes column (uses timestampTz without updated_at)
    const DELETED_AT = 'deleted_at';

    public $timestamps = false;

    protected $casts = [
        'document_type'   => KycDocumentType::class,
        'document_number' => 'encrypted',        // AES-256-CBC ciphertext (E26 FR-008)
        'extracted_data'  => 'encrypted:array',  // AES-256-CBC ciphertext, decoded as array
        'file_size_bytes' => 'integer',
        'expires_on'      => 'date',
        'deleted_at'      => 'datetime',
        'created_at'      => 'datetime',
    ];

    public function kycCase(): BelongsTo
    {
        return $this->belongsTo(KycCaseModel::class, 'kyc_case_id');
    }
}
