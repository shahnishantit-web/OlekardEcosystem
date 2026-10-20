<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Http\Resources\CurrencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CurrencyController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly CurrencyRepositoryInterface $currencies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $currencies = $this->currencies->listActive();

        return $this->ok(
            CurrencyResource::collection($currencies)->toArray($request),
            $correlationId
        );
    }
}
