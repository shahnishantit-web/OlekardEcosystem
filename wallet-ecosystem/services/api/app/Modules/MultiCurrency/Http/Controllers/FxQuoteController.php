<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use App\Modules\MultiCurrency\Application\DTOs\FxQuoteConfirmDTO;
use App\Modules\MultiCurrency\Application\DTOs\FxQuoteRequestDTO;
use App\Modules\MultiCurrency\Application\Services\FxConversionService;
use App\Modules\MultiCurrency\Application\Services\FxQuoteService;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\FxQuoteExpiredException;
use App\Modules\MultiCurrency\Domain\Exceptions\FxQuoteNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\FxRateNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\MultiCurrency\Http\Requests\FxQuoteConfirmRequest;
use App\Modules\MultiCurrency\Http\Requests\FxQuoteRequest;
use App\Modules\MultiCurrency\Http\Resources\FxQuoteResource;
use App\Modules\MultiCurrency\Http\Resources\FxTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class FxQuoteController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly FxQuoteService      $quoteService,
        private readonly FxConversionService $conversionService,
    ) {}

    public function store(FxQuoteRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $customerId     = $request->attributes->get('identity.user_id');

        if (! $idempotencyKey) {
            return $this->fail('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $quote = $this->quoteService->requestQuote(new FxQuoteRequestDTO(
                customerId:       $customerId,
                fromCurrencyCode: strtoupper($request->validated('from_currency_code')),
                toCurrencyCode:   strtoupper($request->validated('to_currency_code')),
                fromAmount:       (string) $request->validated('from_amount'),
                idempotencyKey:   $idempotencyKey,
                correlationId:    $correlationId,
            ));

            return $this->ok(
                (new FxQuoteResource($quote))->toArray($request),
                $correlationId,
                201
            );
        } catch (FxRateNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (CurrencyNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (InsufficientBalanceException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }

    public function confirm(FxQuoteConfirmRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->validated('idempotency_key');
        $customerId     = $request->attributes->get('identity.user_id');

        try {
            $transaction = $this->conversionService->confirm(new FxQuoteConfirmDTO(
                customerId:     $customerId,
                quoteId:        $id,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            ));

            return $this->ok(
                (new FxTransactionResource($transaction))->toArray($request),
                $correlationId
            );
        } catch (FxQuoteNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (FxQuoteExpiredException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (InsufficientBalanceException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }
}
