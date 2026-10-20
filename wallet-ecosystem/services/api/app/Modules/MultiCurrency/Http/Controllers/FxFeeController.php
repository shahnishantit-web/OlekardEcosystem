<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use App\Modules\MultiCurrency\Application\Services\FxQuoteService;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\FxRateNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FxFeeController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly FxQuoteService $fxQuoteService,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $validated = $request->validate([
            'from_currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'to_currency_code'   => ['required', 'string', 'size:3', 'alpha'],
            'from_amount'        => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $preview = $this->fxQuoteService->previewFee(
                fromCurrency: strtoupper($validated['from_currency_code']),
                toCurrency:   strtoupper($validated['to_currency_code']),
                fromAmount:   (string) $validated['from_amount'],
            );

            return $this->ok($preview, $correlationId);
        } catch (FxRateNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (CurrencyNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }
}
