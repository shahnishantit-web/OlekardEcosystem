<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\BankAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Thin controller — all business logic in BankAdminService.
 * All endpoints require payment.configuration permission (enforced via route middleware).
 */
final class BankAdminController extends Controller
{
    public function __construct(
        private readonly BankAdminService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'code', 'display_name']);
        $perPage = (int) $request->query('per_page', '25');

        $paginator = $this->service->listBanks($filters, min($perPage, 100));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $bankId): JsonResponse
    {
        $bank = $this->service->findBank($bankId);

        if ($bank === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Bank not found.']], 404);
        }

        return response()->json(['data' => $bank->toArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'                  => 'required|string|max:32|regex:/^[A-Z0-9_-]+$/',
            'display_name'          => 'required|string|max:255',
            'adapter_identifier'    => 'required|string|max:128',
            'adapter_version'       => 'required|string|max:32',
            'environment'           => 'required|string|in:sandbox,production',
            'supported_countries'   => 'nullable|array',
            'supported_countries.*' => 'string|size:2',
            'organisation_ownership' => 'nullable|uuid',
            'description'           => 'nullable|string|max:2000',
            'logo_reference'        => 'nullable|string|max:500',
        ]);

        $actorId       = (string) $request->user()?->id;
        $correlationId = $request->header('Idempotency-Key', (string) \Illuminate\Support\Str::uuid());

        $bank = $this->service->createBank($validated, $actorId, $correlationId);

        return response()->json(['data' => $bank->toArray()], 201);
    }

    public function update(Request $request, string $bankId): JsonResponse
    {
        $validated = $request->validate([
            'display_name'           => 'sometimes|string|max:255',
            'supported_countries'    => 'sometimes|array',
            'supported_countries.*'  => 'string|size:2',
            'organisation_ownership' => 'nullable|uuid',
            'description'            => 'nullable|string|max:2000',
            'logo_reference'         => 'nullable|string|max:500',
            'metadata_version'       => 'required|integer|min:1',
        ]);

        $actorId = (string) $request->user()?->id;
        $version = (int) $validated['metadata_version'];

        $bank = $this->service->updateBank($bankId, $validated, $version, $actorId);

        return response()->json(['data' => $bank->toArray()]);
    }
}
