<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServiceValidationService;
use App\Modules\Service\Infrastructure\Http\Requests\CalculateServiceFeeRequest;
use App\Modules\Service\Infrastructure\Http\Requests\ValidateCustomerReferenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ServiceValidationController extends Controller
{
    public function __construct(
        private readonly ServiceValidationService $validation,
    ) {}

    public function validateReference(ValidateCustomerReferenceRequest $request, string $id): JsonResponse
    {
        $result = $this->validation->validateReference(
            serviceId:          $id,
            customerReference:  $request->validated('customer_reference'),
            correlationId:      $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return response()->json($result);
    }

    public function calculateFee(CalculateServiceFeeRequest $request, string $id): JsonResponse
    {
        $result = $this->validation->calculateFee(
            serviceId: $id,
            amount:    (int) $request->validated('amount'),
        );

        return response()->json($result);
    }
}
