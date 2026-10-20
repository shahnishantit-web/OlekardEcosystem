<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string       $id
 * @property string       $ticket_id
 * @property string       $author_id
 * @property string       $author_type
 * @property string       $content
 * @property bool         $is_internal
 * @property Carbon|null  $created_at
 */
final class TicketNoteModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'support.ticket_notes';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_internal' => 'bool',
        'created_at'  => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicketModel::class, 'ticket_id');
    }

    public function scopePublicOnly(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
