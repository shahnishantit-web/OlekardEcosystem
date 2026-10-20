<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Controllers;

use App\Modules\Fees\Application\Services\FeeCalculationService;
use App\Modules\Fees\Infrastructure\Http\Requests\CalculateFeeRequest;
use App\Modules\Fees\Infrastructure\Http\Resources\FeeCalculationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class FeeCalculateController extends Controller
{
    public function __construct(private readonly FeeCalculationService $service) {}

    public function calculate(CalculateFeeRequest $request): JsonResponse
    {
        $result = $this->service->calculate(
            transactionType: $request->validated('transaction_type'),
            amountMinor:     (int) $request->validated('amount_minor'),
            currency:        $request->validated('currency', 'BWP') ?? 'BWP',
            subjectType:     $request->validated('subject_type'),
            subjectId:       $request->validated('subject_id'),
        );

        return (new FeeCalculationResource($result))
            ->response()
            ->setStatusCode(200);
    }
}
