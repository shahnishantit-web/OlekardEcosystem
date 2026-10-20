<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Domain\Contracts\FiaReportingInterface;
use App\Modules\Risk\Domain\Enums\SarStatus;
use App\Modules\Risk\Domain\Models\SuspiciousActivityReport;
use App\Modules\Risk\Infrastructure\Http\Resources\SarResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class SarController extends Controller
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly FiaReportingInterface $fia,
    ) {}

    // ── T119: List SARs ───────────────────────────────────────────────────────

    public function list(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = SuspiciousActivityReport::query()->latest();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        return SarResource::collection($query->paginate($perPage));
    }

    // ── T119: Show SAR ────────────────────────────────────────────────────────

    public function show(Request $request, string $id): SarResource|JsonResponse
    {
        $sar = SuspiciousActivityReport::find($id);

        if ($sar === null) {
            return response()->json(['message' => 'SAR not found.'], 404);
        }

        return new SarResource($sar);
    }

    // ── T120: Approve SAR ─────────────────────────────────────────────────────

    public function approve(Request $request, string $id): SarResource|JsonResponse
    {
        $request->validate([
            'approver_id' => ['required', 'uuid'],
        ]);

        $approverId = $request->input('approver_id');

        return DB::connection('risk')->transaction(function () use ($id, $approverId): SarResource|JsonResponse {
            $sar = SuspiciousActivityReport::lockForUpdate()->find($id);

            if ($sar === null) {
                return response()->json(['message' => 'SAR not found.'], 404);
            }

            if ($sar->status !== SarStatus::Draft) {
                return response()->json(['message' => 'SAR is already approved or beyond.'], 409);
            }

            if ($approverId === $sar->filer_id) {
                return response()->json(['message' => 'Approver cannot be the same as the filer (maker-checker violation).'], 422);
            }

            $sar->update([
                'status'      => SarStatus::Approved->value,
                'approver_id' => $approverId,
                'approved_at' => now(),
            ]);

            $this->audit->record(new AuditEventData(
                action:        'risk.sar.approved',
                actorType:     'user',
                resourceType:  'suspicious_activity_report',
                correlationId: (string) $sar->id,
                outcome:       'success',
                metadata:      [
                    'sar_id'      => $sar->id,
                    'approver_id' => $approverId,
                    'filer_id'    => $sar->filer_id,
                ],
            ));

            return new SarResource($sar->fresh());
        });
    }

    // ── T121: Submit SAR to FIA ───────────────────────────────────────────────

    public function submit(Request $request, string $id): SarResource|JsonResponse
    {
        $sar = SuspiciousActivityReport::find($id);

        if ($sar === null) {
            return response()->json(['message' => 'SAR not found.'], 404);
        }

        if ($sar->status !== SarStatus::Approved) {
            return response()->json(['message' => 'SAR must be approved before submission.'], 422);
        }

        try {
            $fiaReference = $this->fia->submit($sar);

            $sar->update([
                'status'               => SarStatus::Submitted->value,
                'fia_reference'        => $fiaReference,
                'submitted_at'         => now(),
                'submission_attempts'  => $sar->submission_attempts + 1,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'risk.sar.submitted',
                actorType:     'user',
                resourceType:  'suspicious_activity_report',
                correlationId: (string) $sar->id,
                outcome:       'success',
                metadata:      ['sar_id' => $sar->id, 'fia_reference' => $fiaReference],
            ));
        } catch (\RuntimeException $e) {
            $sar->update([
                'status'               => SarStatus::SubmissionFailed->value,
                'last_submission_error' => $e->getMessage(),
                'submission_attempts'  => $sar->submission_attempts + 1,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'risk.sar.submission_failed',
                actorType:     'user',
                resourceType:  'suspicious_activity_report',
                correlationId: (string) $sar->id,
                outcome:       'failure',
                metadata:      ['sar_id' => $sar->id, 'error' => $e->getMessage()],
            ));

            return response()->json([
                'message' => 'FIA submission failed.',
                'error'   => $e->getMessage(),
                'data'    => new SarResource($sar->fresh()),
            ], 502);
        }

        return new SarResource($sar->fresh());
    }
}
