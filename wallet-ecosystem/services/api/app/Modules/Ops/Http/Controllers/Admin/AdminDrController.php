<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Ops\Application\Services\DisasterRecoveryService;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminDrController extends OpsBaseController
{
    public function __construct(
        private readonly DisasterRecoveryService $drService,
        private readonly AuditService $audit,
    ) {}

    public function status(): JsonResponse
    {
        return $this->ok($this->drService->getStatus());
    }

    public function recordTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => 'required|string|in:pass,fail,partial',
            'notes'   => 'nullable|string|max:2000',
        ]);

        $actorId       = (string) $request->user()?->id;
        $correlationId = $request->header('Idempotency-Key', Str::uuid()->toString());

        $result = $this->drService->recordTest(
            testedBy:      $actorId,
            outcome:       $validated['outcome'],
            notes:         $validated['notes'] ?? '',
            correlationId: $correlationId,
        );

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.dr_test.recorded',
                actorType:     AuditActorType::Admin,
                action:        'record_dr_test',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withAfterState($result)
        );

        return $this->ok($result, 201);
    }
}
