<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\ApiOperationConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Thin controller for API operation configuration management.
 * Requires payment.configuration permission (route middleware).
 */
final class ApiOperationConfigAdminController extends Controller
{
    public function __construct(
        private readonly ApiOperationConfigService $service,
    ) {
    }

    public function index(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $operations = $this->service->listOperations($accountId);

        return response()->json(['data' => $operations->values()]);
    }

    public function save(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'operations'                               => 'required|array|min:1',
            'operations.*.operation_type'              => 'required|string',
            'operations.*.base_url'                    => 'required|string|max:500',
            'operations.*.endpoint_path'               => 'required|string|max:500',
            'operations.*.http_method'                 => 'required|string',
            'operations.*.auth_profile'                => 'required|string',
            'operations.*.success_status_values'       => 'nullable|array',
            'operations.*.pending_status_values'       => 'nullable|array',
            'operations.*.failure_status_values'       => 'nullable|array',
            'operations.*.timeout_ms'                  => 'nullable|integer|min:1|max:120000',
            'operations.*.request_field_mappings'      => 'nullable|array',
            'operations.*.response_field_mappings'     => 'nullable|array',
            'operations.*.secret_key_references'       => 'nullable|array',
        ]);

        $actorId = (string) $request->user()?->id;

        try {
            $result = $this->service->saveOperations($accountId, $validated['operations'], $actorId);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'validation_failed', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'data'          => $result['saved'],
            'draft_created' => $result['draft_created'],
        ]);
    }

    public function validate(Request $request, string $bankId, string $accountId): JsonResponse
    {
        $validated = $request->validate([
            'operations'   => 'required|array|min:1',
            'operations.*' => 'array',
        ]);

        $errors = $this->service->validateOperations($accountId, $validated['operations']);

        if (!empty($errors)) {
            return response()->json([
                'valid'  => false,
                'errors' => $errors,
            ]);
        }

        return response()->json(['valid' => true, 'errors' => []]);
    }
}
