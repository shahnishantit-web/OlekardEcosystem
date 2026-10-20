<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers\Admin;

use App\Modules\MultiCurrency\Application\Services\AdminFxManagementService;
use App\Modules\MultiCurrency\Domain\Exceptions\FxProviderUnavailableException;
use App\Modules\MultiCurrency\Http\Controllers\MultiCurrencyBaseController;
use App\Modules\MultiCurrency\Http\Resources\FxRateResource;
use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AdminFxManagementController extends MultiCurrencyBaseController
{
    public function __construct(private readonly AdminFxManagementService $service) {}

    private function run(Request $r, callable $fn, int $status = 200): JsonResponse
    {
        $correlation = $r->header('X-Correlation-ID', (string) Uuid::uuid7());
        try {
            return $this->ok($fn((string) $r->attributes->get('identity.user_id'), (string) $r->header('Idempotency-Key', ''), $correlation), $correlation, $status);
        } catch (\DomainException $e) {
            return $this->fail('FX_CONFIGURATION_INVALID', $e->getMessage(), $e->getMessage() === 'Currency not found.' ? 404 : 422, $correlation);
        } catch (FxProviderUnavailableException $e) {
            return $this->fail('FX_PROVIDER_UNAVAILABLE', $e->getMessage(), 503, $correlation);
        }
    }

    public function currencies(Request $r): JsonResponse
    {
        return $this->run($r, fn () => $this->service->currencies());
    }

    public function updateCurrency(Request $r, string $code): JsonResponse
    {
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:100'], 'symbol' => ['sometimes', 'string', 'max:10'], 'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:8'], 'is_active' => ['sometimes', 'boolean']]);

        return $this->run($r, fn ($actor, $key, $c) => $this->service->updateCurrency(strtoupper($code), $d, $actor, $key, $c));
    }

    public function activateCurrency(Request $r, string $code, string $request): JsonResponse
    {
        return $this->run($r, fn ($a, $k, $c) => $this->service->activateCurrency(strtoupper($code), $request, $a, $k, $c));
    }

    public function rates(Request $r): JsonResponse
    {
        return $this->run($r, fn () => FxRateResource::collection(FxRateModel::whereIn('status', ['active', 'pending_approval'])->whereHas('fromCurrency', fn ($q) => $q->where('is_active', true))->whereHas('toCurrency', fn ($q) => $q->where('is_active', true))->orderBy('from_currency_code')->orderBy('to_currency_code')->orderByDesc('created_at')->get())->resolve());
    }

    public function storeRate(Request $r): JsonResponse
    {
        $d = $r->validate(['from_currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/D'], 'to_currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/D'], 'rate' => ['required_unless:mode,provider', 'string'], 'spread' => ['required', 'string', 'regex:/^0(?:\.[0-9]{1,5})?$/D'], 'mode' => ['sometimes', 'in:manual,provider'], 'effective_from' => ['sometimes', 'date_format:Y-m-d\TH:i:sP']]);
        if (! Uuid::isValid((string) $r->header('Idempotency-Key'))) {
            return $this->fail('VALIDATION_ERROR', 'UUID Idempotency-Key required.', 422, (string) $r->header('X-Correlation-ID', ''));
        }

        return $this->run($r, fn ($a, $k, $c) => (new FxRateResource($this->service->proposeRate($d, $a, $k, $c)))->resolve(), 201);
    }

    public function activateRate(Request $r, string $id): JsonResponse
    {
        return $this->run($r, fn ($a, $k, $c) => (new FxRateResource($this->service->activateRate($id, $a, $k, $c)))->resolve());
    }

    public function history(Request $r, string $pair): JsonResponse
    {
        $d = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->run($r, fn () => $this->service->history(strtoupper($pair), (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 25)));
    }
}
