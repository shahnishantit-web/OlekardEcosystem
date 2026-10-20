<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Models;

use App\Modules\Agent\Domain\Enums\DocumentStatus;
use App\Modules\Agent\Domain\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDocumentModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'agent.agent_documents';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'        => DocumentStatus::class,
        'document_type' => DocumentType::class,
        'size_bytes'    => 'integer',
        'uploaded_at'   => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AgentApplicationModel::class, 'application_id');
    }
}
