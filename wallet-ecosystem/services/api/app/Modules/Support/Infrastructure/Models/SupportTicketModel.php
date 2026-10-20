<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Models;

use App\Modules\Support\Domain\Enums\TicketCategory;
use App\Modules\Support\Domain\Enums\TicketPriority;
use App\Modules\Support\Domain\Enums\TicketStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string           $id
 * @property string           $ticket_number
 * @property string           $subject
 * @property string           $description
 * @property TicketCategory   $category
 * @property TicketPriority   $priority
 * @property TicketStatus     $status
 * @property string           $reporter_id
 * @property string           $reporter_type
 * @property string|null      $assignee_id
 * @property string|null      $dispute_id
 * @property string|null      $resolution_note
 * @property Carbon|null      $resolved_at
 * @property Carbon|null      $closed_at
 * @property string|null      $idempotency_key
 * @property string           $correlation_id
 * @property Carbon|null      $created_at
 * @property Carbon|null      $updated_at
 */
final class SupportTicketModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'support.support_tickets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'      => TicketStatus::class,
        'category'    => TicketCategory::class,
        'priority'    => TicketPriority::class,
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(TicketNoteModel::class, 'ticket_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeModel::class, 'dispute_id');
    }

    public function scopeForReporter(Builder $query, string $reporterId): Builder
    {
        return $query->where('reporter_id', $reporterId);
    }

    public function scopeForStatus(Builder $query, TicketStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
