<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class KycController
{
    public function __construct(
        private readonly PendingAdminActionService $pendingActions,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('kyc')->table('kyc.kyc_cases as k')->select([
            'k.id',
            'k.user_id',
            'k.status',
            'k.submitted_at',
            'k.reviewed_at',
            'k.reviewer_id',
            'k.rejection_reason',
        ]);

        $status = $request->query('status', 'pending_review');
        if ($status !== 'all') {
            if ($status === 'pending_review') {
                $query->where('k.status', 'under_review');
            } else {
                $query->where('k.status', $status);
            }
        }

        if ($tier = $request->query('tier')) {
            // KYC cases do not own a tier field. Preserve an honest unassigned tier instead of
            // interpreting attempt_number or another module's data as a KYC tier.
            if (!in_array((string) $tier, ['1', '2', '3'], true)) {
                $query->whereRaw('1 = 0');
            }
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('k.submitted_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('k.submitted_at', '<=', $dateTo);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page    = (int) $request->query('page', 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $cases = $paginated->getCollection();
        $userIds = $cases->pluck('user_id')->unique()->values();
        $caseIds = $cases->pluck('id')->values();
        $profiles = DB::connection('profile')->table('profile.user_profiles')->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $documents = DB::connection('kyc')->table('kyc.kyc_documents')->whereIn('kyc_case_id', $caseIds)
            ->whereNull('deleted_at')->orderByDesc('created_at')->get(['kyc_case_id', 'document_type'])->groupBy('kyc_case_id');

        $data = $cases->map(function ($item) use ($profiles, $documents): array {
            $profile = $profiles->get($item->user_id);
            $documentType = $documents->get($item->id)?->first()?->document_type ?? 'unknown';
            $name = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));

            return [
                'id'                 => $item->id,
                'kyc_submission_id'  => $item->id,
                'user_id'            => $item->user_id,
                'user_name'          => $name,
                'user_full_name'     => $name,
                'user_phone_number'  => $profile->mobile_number ?? null,
                'requested_tier'     => null,
                'tier_applied'       => null,
                'current_tier'       => null,
                'risk_score'         => null,
                'status'             => $item->status === 'under_review' ? 'pending_review' : $item->status,
                'document_type'      => $documentType,
                'kyc_type'           => $documentType,
                'document_urls'      => [],
                'document_front_url' => null,
                'document_back_url'  => null,
                'submitted_at'       => $item->submitted_at,
                'reviewed_at'        => $item->reviewed_at,
                'reviewed_by'        => $item->reviewer_id,
                'rejection_reason'   => $item->rejection_reason,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $kycSubmissionId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $item = DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $kycSubmissionId)->first();

        if ($item === null) {
            return ErrorResource::make('ADMINAPP_KYC_001', 'KYC submission not found.', $correlationId, 404);
        }

        $profile = DB::connection('profile')->table('profile.user_profiles')->where('user_id', $item->user_id)->first();
        $personal = DB::connection('kyc')->table('kyc.kyc_personal_info')->where('kyc_case_id', $item->id)->first();
        $documents = DB::connection('kyc')->table('kyc.kyc_documents')->where('kyc_case_id', $item->id)
            ->whereNull('deleted_at')->orderByDesc('created_at')->get(['document_type']);
        $verificationResults = DB::connection('kyc')->table('kyc.kyc_verification_results')
            ->where('kyc_case_id', $item->id)->orderByDesc('created_at')
            ->get(['provider_name', 'outcome', 'confidence_score', 'created_at']);
        $documentType = $documents->first()?->document_type ?? 'unknown';
        $name = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''));

        return response()->json(['data' => [
            'id'                 => $item->id,
            'kyc_submission_id'  => $item->id,
            'user_id'            => $item->user_id,
            'user_name'          => $name,
            'user_full_name'     => $name,
            'user_phone_number'  => $profile->mobile_number ?? null,
            'requested_tier'     => null,
            'tier_applied'       => null,
            'current_tier'       => null,
            'risk_score'         => null,
            'status'             => $item->status === 'under_review' ? 'pending_review' : $item->status,
            'document_type'      => $documentType,
            'kyc_type'           => $documentType,
            'document_urls'      => [],
            'document_front_url' => null,
            'document_back_url'  => null,
            'submitted_at'       => $item->submitted_at,
            'reviewed_at'        => $item->reviewed_at,
            'reviewed_by'        => $item->reviewer_id,
            'rejection_reason'   => $item->rejection_reason,
            'personal_info'      => $personal ? [
                'full_legal_name' => $personal->full_legal_name,
                'date_of_birth'   => $personal->date_of_birth,
                'nationality'     => $personal->nationality,
                'address'         => [
                    'line_1'        => $personal->address_line_1,
                    'city'          => $personal->city,
                    'country_code'  => $personal->country_code,
                ],
            ] : null,
            'verification_results' => $verificationResults->map(fn ($result): array => [
                'provider_name'     => $result->provider_name,
                'outcome'           => $result->outcome,
                'confidence_score'  => $result->confidence_score !== null ? (float) $result->confidence_score : null,
                'created_at'        => $result->created_at,
            ])->values()->all(),
        ]]);
    }

    public function approve(Request $request, string $kycSubmissionId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');

        $item = DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $kycSubmissionId)->first(['id', 'status']);

        if ($item === null) {
            return ErrorResource::make('ADMINAPP_KYC_001', 'KYC submission not found.', $correlationId, 404);
        }

        if ($item->status !== 'under_review') {
            return ErrorResource::make('ADMINAPP_KYC_002', 'KYC submission is not in pending_review state.', $correlationId, 422);
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.kyc.approve',
            resourceType:   'KycSubmission',
            resourceId:     $kycSubmissionId,
            payload:        [],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.kyc.approve.requested',
            actorType:     'USER',
            resourceType:  'KycSubmission',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $kycSubmissionId,
            afterState:    ['approval_id' => $pendingAction->id],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.kyc.approve',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }

    public function reject(Request $request, string $kycSubmissionId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $reason         = $request->input('reason', '');

        $item = DB::connection('kyc')->table('kyc.kyc_cases')->where('id', $kycSubmissionId)->first(['id', 'status']);

        if ($item === null) {
            return ErrorResource::make('ADMINAPP_KYC_001', 'KYC submission not found.', $correlationId, 404);
        }

        if ($item->status !== 'under_review') {
            return ErrorResource::make('ADMINAPP_KYC_002', 'KYC submission is not in pending_review state.', $correlationId, 422);
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.kyc.reject',
            resourceType:   'KycSubmission',
            resourceId:     $kycSubmissionId,
            payload:        ['reason' => $reason],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.kyc.reject.requested',
            actorType:     'USER',
            resourceType:  'KycSubmission',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $kycSubmissionId,
            afterState:    ['approval_id' => $pendingAction->id, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.kyc.reject',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }
}
