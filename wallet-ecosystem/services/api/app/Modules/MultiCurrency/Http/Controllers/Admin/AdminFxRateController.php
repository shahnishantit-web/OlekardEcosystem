<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers\Admin;

use App\Modules\MultiCurrency\Application\Services\FxRateAdminService;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyNotFoundException;
use App\Modules\MultiCurrency\Http\Controllers\MultiCurrencyBaseController;
use App\Modules\MultiCurrency\Http\Resources\FxRateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class AdminFxRateController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly FxRateRepositoryInterface $fxRates,
        private readonly FxRateAdminService $fxRateAdminService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $activeOnly = filter_var($request->query('active_only', 'true'), FILTER_VALIDATE_BOOLEAN);

        $rates = $activeOnly
            ? $this->fxRates->listActive()
            : $this->fxRates->listAll();

        return $this->ok(
            FxRateResource::collection($rates)->toArray($request),
            $correlationId
        );
    }

    public function store(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $adminId = $request->attributes->get('identity.user_id');

        if (! $idempotencyKey) {
            return $this->fail('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $validated = $request->validate([
            'from_currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'to_currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'spread' => ['required', 'numeric', 'gte:0', 'lt:1'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d\TH:i:sP'],
        ]);

        try {
            $rate = $this->fxRateAdminService->submitRateChange(
                fromCurrencyCode: strtoupper($validated['from_currency_code']),
                toCurrencyCode: strtoupper($validated['to_currency_code']),
                rate: (string) $validated['rate'],
                spread: (string) $validated['spread'],
                effectiveFrom: $validated['effective_from'] ?? now()->toIso8601String(),
                adminId: $adminId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                requestedEffectiveFrom: $validated['effective_from'] ?? null,
            );

            return $this->ok(
                (new FxRateResource($rate))->toArray($request),
                $correlationId,
                201
            );
        } catch (CurrencyNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }

    /**
     * Activate a pending FX rate (checker approval step).
     * POST /v1/admin/multi-currency/fx-rates/{id}/activate
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $checkerId = $request->attributes->get('identity.user_id');
        if (! Uuid::isValid((string) $request->header('Idempotency-Key'))) {
            return $this->fail('VALIDATION_ERROR', 'UUID Idempotency-Key required.', 422, $correlationId);
        }

        try {
            $rate = $this->fxRateAdminService->activateRate(
                rateId: $id,
                checkerId: $checkerId,
                correlationId: $correlationId,
            );

            return $this->ok(
                (new FxRateResource($rate))->toArray($request),
                $correlationId,
            );
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }
}
