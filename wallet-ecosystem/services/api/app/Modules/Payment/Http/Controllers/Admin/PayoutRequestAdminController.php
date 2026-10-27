<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PayoutBalanceService;
use App\Modules\Payment\Application\Services\PayoutBeneficiaryService;
use App\Modules\Payment\Application\Services\PayoutDomainEntitlementService;
use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PayoutRequestStatus;
use App\Modules\Payment\Domain\Exceptions\BeneficiaryNotVerifiedException;
use App\Modules\Payment\Domain\Exceptions\DomainEntitlementDeniedException;
use App\Modules\Payment\Http\Resources\PayoutRequestResource;
use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class PayoutRequestAdminController extends Controller
{
    public function __construct(
        private readonly PayoutBeneficiaryService          $beneficiaryService,
        private readonly PayoutDomainEntitlementService    $entitlementService,
        private readonly PayoutBalanceService              $balanceService,
        private readonly PayoutRequestRepositoryInterface  $repository,
    ) {}

    public function initiate(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'fund_reference_id' => ['required', 'uuid'],
            'purpose_type'      => ['required', 'string', 'max:100'],
            'organisation_id'   => ['required', 'uuid'],
            'beneficiary_id'    => ['required', 'uuid'],
            'currency_code'     => ['required', 'string', 'size:3'],
            'custody_wallet_id' => ['required', 'uuid'],
        ])->validate();

        $actorId = (string) $request->user()?->id;

        try {
            $this->entitlementService->assertEntitled(
                $validated['fund_reference_id'],
                $validated['purpose_type'],
                $validated['organisation_id'],
                $actorId,
            );
        } catch (DomainEntitlementDeniedException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'DOMAIN_ENTITLEMENT_DENIED', 'message' => $e->getMessage()],
            ], 403);
        }

        try {
            $this->beneficiaryService->assertVerified($validated['beneficiary_id']);
        } catch (BeneficiaryNotVerifiedException $e) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'BENEFICIARY_NOT_VERIFIED', 'message' => $e->getMessage()],
            ], 400);
        }

        $balance = $this->balanceService->computeForFund(
            $validated['fund_reference_id'],
            $validated['purpose_type'],
            $validated['currency_code'],
            $validated['custody_wallet_id'],
        );

        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $payoutRequest = new PayoutRequestModel([
                'id'                     => (string) Uuid::uuid7(),
                'idempotency_key'        => $idempotencyKey,
                'fund_reference_id'      => $validated['fund_reference_id'],
                'purpose_type'           => $validated['purpose_type'],
                'organisation_id'        => $validated['organisation_id'],
                'beneficiary_id'         => $validated['beneficiary_id'],
                'currency_code'          => $validated['currency_code'],
                'requested_amount_minor' => 0,
                'computed_payable_minor' => $balance->payableMinor(),
                'custody_wallet_id'      => $validated['custody_wallet_id'],
                'status'                 => PayoutRequestStatus::AwaitingApproval,
                'created_by'             => $actorId,
            ]);

            $this->repository->save($payoutRequest);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                $existing = $this->repository->findByIdempotencyKey((string) $idempotencyKey);
                return response()->json([
                    'success'     => false,
                    'error'       => ['code' => 'DUPLICATE_KEY', 'message' => 'Idempotency key already used.'],
                    'existing_id' => $existing?->id,
                ], 409);
            }
            throw $e;
        }

        return (new PayoutRequestResource($payoutRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'fund_reference_id' => $request->input('fund_reference_id'),
            'organisation_id'   => $request->input('organisation_id'),
            'status'            => $request->input('status'),
        ], fn ($v): bool => $v !== null);

        $perPage   = (int) $request->input('per_page', 20);
        $paginator = $this->repository->paginate($filters, $perPage);

        return PayoutRequestResource::collection($paginator);
    }

    public function show(string $id): JsonResponse
    {
        $payoutRequest = $this->repository->findById($id);

        if ($payoutRequest === null) {
            return response()->json(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
        }

        $payoutRequest->load('reservation');

        return (new PayoutRequestResource($payoutRequest))->response();
    }

    public function balance(string $id): JsonResponse
    {
        $payoutRequest = $this->repository->findById($id);

        if ($payoutRequest === null) {
            return response()->json(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
        }

        $balance = $this->balanceService->computeForFund(
            $payoutRequest->fund_reference_id,
            $payoutRequest->purpose_type,
            $payoutRequest->currency_code,
            $payoutRequest->custody_wallet_id,
        );

        return response()->json(['success' => true, 'data' => $balance->toArray()]);
    }
}
