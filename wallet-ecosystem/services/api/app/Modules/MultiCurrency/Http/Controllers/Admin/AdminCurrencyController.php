<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers\Admin;

use App\Modules\MultiCurrency\Application\Services\AdminFxManagementService;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Http\Controllers\MultiCurrencyBaseController;
use App\Modules\MultiCurrency\Http\Resources\CurrencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminCurrencyController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly CurrencyRepositoryInterface $currencies,
        private readonly AdminFxManagementService $management,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $currencies = $this->currencies->listAll();

        return $this->ok(
            CurrencyResource::collection($currencies)->toArray($request),
            $correlationId
        );
    }

    public function store(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:10'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:8'],
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            return $this->ok($this->management->registerCurrency($validated, (string) $request->attributes->get('identity.user_id'), $correlationId), $correlationId, 201);
        } catch (\DomainException $e) {
            return $this->fail('APPROVAL_REQUIRED', $e->getMessage(), 422, $correlationId);
        }
    }

    public function update(Request $request, string $code): JsonResponse
    {
        return app(AdminFxManagementController::class)->updateCurrency($request, $code);
    }
}
