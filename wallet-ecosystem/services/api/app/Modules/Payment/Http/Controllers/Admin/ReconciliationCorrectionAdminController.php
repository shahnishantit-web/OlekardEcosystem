<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\ReconciliationCorrectionService;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Domain\Contracts\ReconciliationCorrectionRepositoryInterface;
use App\Modules\Payment\Domain\Exceptions\CorrectionApprovalException;
use App\Modules\Payment\Http\Resources\ReconciliationCorrectionRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReconciliationCorrectionAdminController extends Controller
{
    public function __construct(
        private readonly ReconciliationCorrectionRepositoryInterface $repository,
        private readonly ReconciliationCorrectionService             $correctionService,
        private readonly ReconciliationExceptionService              $exceptionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = array_filter($request->only(['status', 'exception_id']));
        $perPage = (int) $request->input('per_page', 20);

        return ReconciliationCorrectionRequestResource::collection(
            $this->repository->list($filters, $perPage)
        );
    }

    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'exception_id'       => ['required', 'string', 'uuid'],
            'correction_type'    => ['required', 'string', 'max:50'],
            'description'        => ['required', 'string', 'min:10'],
            'evidence_note'      => ['required', 'string', 'min:10'],
            'amount_minor'       => ['required', 'integer', 'min:1'],
            'currency_code'      => ['required', 'string', 'size:3'],
            'evidence_reference' => ['nullable', 'url', 'max:500'],
            'debit_account'      => ['nullable', 'string', 'max:100'],
            'credit_account'     => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $record = $this->correctionService->submit(
                exceptionId:       $request->input('exception_id'),
                correctionType:    $request->input('correction_type'),
                description:       $request->input('description'),
                evidenceNote:      $request->input('evidence_note'),
                amountMinor:       (int) $request->input('amount_minor'),
                currencyCode:      $request->input('currency_code'),
                requestedBy:       (string) $request->user()?->id,
                evidenceReference: $request->input('evidence_reference'),
                debitAccount:      $request->input('debit_account'),
                creditAccount:     $request->input('credit_account'),
            );

            return response()->json(['data' => (new ReconciliationCorrectionRequestResource($record))->toArray($request)], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'recon_corrections_idempotency_key_idx')) {
                return response()->json(['message' => 'A correction request for this exception already exists.'], 409);
            }
            throw $e;
        }
    }

    public function approve(Request $request, string $correctionId): JsonResponse
    {
        try {
            $record = $this->correctionService->approve($correctionId, (string) $request->user()?->id);
            return response()->json(['data' => (new ReconciliationCorrectionRequestResource($record))->toArray($request)]);
        } catch (CorrectionApprovalException $e) {
            if (str_contains($e->getMessage(), 'same actor')) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reject(Request $request, string $correctionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => ['required', 'string', 'min:10'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $record = $this->correctionService->reject(
                $correctionId,
                (string) $request->user()?->id,
                $request->input('rejection_reason')
            );
            return response()->json(['data' => (new ReconciliationCorrectionRequestResource($record))->toArray($request)]);
        } catch (CorrectionApprovalException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function acknowledgeExternal(Request $request, string $exceptionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'evidence_note' => ['required', 'string', 'min:10'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $this->exceptionService->acknowledgeExternal(
                exceptionId: $exceptionId,
                actorId:     (string) $request->user()?->id,
                evidenceNote: $request->input('evidence_note'),
            );

            return response()->json(['message' => 'Exception acknowledged as external charge.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
