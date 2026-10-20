<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Models;

use App\Modules\Support\Domain\Enums\EvidenceFileType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string              $id
 * @property string              $dispute_id
 * @property string              $file_key
 * @property EvidenceFileType    $file_type
 * @property string              $original_name
 * @property string|null         $label
 * @property int                 $size_bytes
 * @property string              $uploaded_by
 * @property Carbon|null         $created_at
 */
final class DisputeEvidenceModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'support.dispute_evidence';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'file_type'  => EvidenceFileType::class,
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeModel::class, 'dispute_id');
    }

    public static function countForDispute(string $disputeId): int
    {
        return (int) static::where('dispute_id', $disputeId)->count();
    }
}
