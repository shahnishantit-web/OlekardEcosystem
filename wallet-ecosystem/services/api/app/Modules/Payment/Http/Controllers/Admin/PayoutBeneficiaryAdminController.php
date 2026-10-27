<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PayoutBeneficiaryService;
use App\Modules\Payment\Domain\Contracts\PayoutBeneficiaryRepositoryInterface;
use App\Modules\Payment\Http\Resources\PayoutBeneficiaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class PayoutBeneficiaryAdminController extends Controller
{
    public function __construct(
        private readonly PayoutBeneficiaryService             $service,
        private readonly PayoutBeneficiaryRepositoryInterface $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = array_filter([
            'organisation_id' => $request->input('organisation_id'),
            'status'          => $request->input('status'),
            'account_type'    => $request->input('account_type'),
        ], fn ($v): bool => $v !== null);

        $perPage    = (int) $request->input('per_page', 20);
        $paginator  = $this->repository->paginate($filters, $perPage);

        return PayoutBeneficiaryResource::collection($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'organisation_id'     => ['required', 'uuid'],
            'account_type'        => ['required', 'string', 'in:bank_account,mobile_money,wallet'],
            'account_number'      => ['required', 'string', 'min:4', 'max:200'],
            'account_holder_name' => ['required', 'string', 'min:2', 'max:200'],
            'currency_code'       => ['required', 'string', 'size:3'],
            'bank_code'           => ['nullable', 'string', 'max:50'],
            'bank_name'           => ['nullable', 'string', 'max:200'],
        ])->validate();

        $actorId = (string) $request->user()?->id;

        try {
            $beneficiary = $this->service->register($validated, $actorId);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return response()->json([
                    'success' => false,
                    'error'   => ['code' => 'DUPLICATE_KEY', 'message' => 'Idempotency key already used.'],
                ], 409);
            }
            throw $e;
        }

        return (new PayoutBeneficiaryResource($beneficiary))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $beneficiary = $this->repository->findById($id);

        if ($beneficiary === null) {
            return response()->json(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
        }

        return (new PayoutBeneficiaryResource($beneficiary))->response();
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        Validator::make($request->all(), [
            'verification_reference' => ['required', 'string', 'max:200'],
            'verification_note'      => ['nullable', 'string', 'max:500'],
        ])->validate();

        $actorId     = (string) $request->user()?->id;
        $beneficiary = $this->service->recordVerification(
            $id,
            $request->input('verification_reference'),
            $actorId,
        );

        return (new PayoutBeneficiaryResource($beneficiary))->response();
    }
}
