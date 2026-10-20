<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\Exceptions\AuditImmutabilityViolationException;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $event_category
 * @property string $event_type
 * @property string|null $actor_id
 * @property string $actor_type
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string $action
 * @property string $outcome
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array|null  $before_state
 * @property array|null  $after_state
 * @property array       $metadata
 * @property string $correlation_id
 * @property string $integrity_hash
 * @property \Carbon\Carbon $occurred_at
 * @property \Carbon\Carbon $created_at
 */
class AuditEventModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'audit.audit_events';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'before_state' => 'array',
        'after_state'  => 'array',
        'metadata'     => 'array',
        'occurred_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
            if (empty($model->occurred_at)) {
                $model->occurred_at = now();
            }
            $model->created_at     = now();
            $model->integrity_hash = $model->computeIntegrityHash();
        });

        static::updating(function (self $model): void {
            static::recordTamperAttempt($model, 'update');
            throw new AuditImmutabilityViolationException();
        });

        static::deleting(function (self $model): void {
            static::recordTamperAttempt($model, 'delete');
            throw new AuditImmutabilityViolationException();
        });
    }

    protected static function recordTamperAttempt(self $model, string $operation): void
    {
        try {
            /** @var AuditModuleContract $audit */
            $audit = app(AuditModuleContract::class);
            $audit->record(
                AuditEntry::make(
                    category:      AuditEventCategory::Security,
                    eventType:     'security.tamper.attempt',
                    actorType:     AuditActorType::System,
                    action:        'Attempted '.$operation.' on immutable audit event '.$model->id,
                    outcome:       AuditOutcome::Denied,
                    correlationId: $model->correlation_id ?? $model->id,
                )->withEntity('audit_event', $model->id)
            );
        } catch (\Throwable) {
            // Never let tamper recording prevent the exception from being thrown
        }
    }

    public function verifyIntegrity(): bool
    {
        return hash_equals($this->integrity_hash, $this->computeIntegrityHash());
    }

    private function computeIntegrityHash(): string
    {
        // Canonical field order per research.md Decision 2
        $canonical = implode('|', [
            (string) $this->id,
            (string) $this->event_category,
            (string) $this->event_type,
            (string) ($this->actor_id ?? ''),
            (string) ($this->entity_type ?? ''),
            (string) ($this->entity_id ?? ''),
            (string) $this->outcome,
            $this->occurred_at instanceof \DateTimeInterface
                ? $this->occurred_at->format('Y-m-d\TH:i:sP')
                : (string) $this->occurred_at,
            (string) $this->correlation_id,
        ]);

        return hash_hmac('sha256', $canonical, (string) config('audit.hmac_key'));
    }
}
