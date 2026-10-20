<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class AuditService implements AuditServiceInterface
{
    public function record(AuditEventData $event): void
    {
        try {
            DB::connection('platform')->table('platform.audit_events')->insert([
                'id'               => (string) Uuid::uuid7(),
                'action'           => $event->action,
                'event_type'       => $event->action,
                'actor_id'         => $event->actorId,
                'actor_type'       => $event->actorType,
                'actor_name_masked' => $event->actorNameMasked,
                'resource_type'    => $event->resourceType,
                'resource_id'      => $event->resourceId,
                'outcome'          => $event->outcome,
                'before_state'     => $event->beforeState !== null ? json_encode($event->beforeState) : null,
                'after_state'      => $event->afterState !== null ? json_encode($event->afterState) : null,
                'payload'          => json_encode(PiiMasker::maskFields($event->metadata)),
                'metadata'         => json_encode(['correlation_id' => $event->correlationId]),
                'correlation_id'   => $event->correlationId,
                'ip_address'       => $event->ipAddress,
                'user_agent'       => $event->userAgent,
                'occurred_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('audit.write_failed', [
                'action'         => $event->action,
                'actor_id'       => $event->actorId,
                'correlation_id' => $event->correlationId,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
