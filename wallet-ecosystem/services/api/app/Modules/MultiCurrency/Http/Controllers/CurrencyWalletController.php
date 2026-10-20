<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use App\Modules\MultiCurrency\Application\DTOs\CreateCurrencyWalletDTO;
use App\Modules\MultiCurrency\Application\DTOs\SameCurrencyTransferDTO;
use App\Modules\MultiCurrency\Application\Services\CurrencyWalletService;
use App\Modules\MultiCurrency\Application\Services\SameCurrencyTransferService;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\CurrencyWalletAlreadyExistsException;
use App\Modules\MultiCurrency\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\MultiCurrency\Http\Requests\CreateCurrencyWalletRequest;
use App\Modules\MultiCurrency\Http\Requests\SameCurrencyTransferRequest;
use App\Modules\MultiCurrency\Http\Resources\CurrencyWalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CurrencyWalletController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly CurrencyWalletService      $walletService,
        private readonly SameCurrencyTransferService $transferService,
        private readonly CurrencyRepositoryInterface $currencies,
    ) {}

    public function store(CreateCurrencyWalletRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $customerId     = $request->attributes->get('identity.user_id');

        if (! $idempotencyKey) {
            return $this->fail('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $wallet   = $this->walletService->create(new CreateCurrencyWalletDTO(
                customerId:     $customerId,
                currencyCode:   $request->validated('currency_code'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            ));
            $currency = $this->currencies->findByCode($wallet->currency_code);
            $balance  = $this->walletService->getBalance($wallet->pocket_ledger_account_reference);

            return $this->ok(
                (new CurrencyWalletResource($wallet, $balance, $currency))->toArray($request),
                $correlationId,
                201
            );
        } catch (CurrencyWalletAlreadyExistsException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (CurrencyNotFoundException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $customerId    = $request->attributes->get('identity.user_id');

        $wallets = $this->walletService->list($customerId);

        $data = $wallets->map(function ($wallet) use ($request): array {
            $currency = $this->currencies->findByCode($wallet->currency_code);
            $balance  = $this->walletService->getBalance($wallet->pocket_ledger_account_reference);
            return (new CurrencyWalletResource($wallet, $balance, $currency))->toArray($request);
        })->values()->toArray();

        return $this->ok($data, $correlationId);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $customerId    = $request->attributes->get('identity.user_id');

        try {
            $wallet   = $this->walletService->get($customerId, $id);
            $currency = $this->currencies->findByCode($wallet->currency_code);
            $balance  = $this->walletService->getBalance($wallet->pocket_ledger_account_reference);

            return $this->ok(
                (new CurrencyWalletResource($wallet, $balance, $currency))->toArray($request),
                $correlationId
            );
        } catch (\DomainException $e) {
            return $this->fail('NOT_FOUND', $e->getMessage(), 404, $correlationId);
        }
    }

    public function transfer(SameCurrencyTransferRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $customerId     = $request->attributes->get('identity.user_id');

        if (! $idempotencyKey) {
            return $this->fail('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $transfer = $this->transferService->transfer(new SameCurrencyTransferDTO(
                customerId:     $customerId,
                fromWalletId:   $id,
                toWalletId:     $request->validated('to_wallet_id'),
                amount:         (string) $request->validated('amount'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            ));

            return $this->ok([
                'transfer_id'    => $transfer->id,
                'status'         => 'completed',
                'amount'         => $transfer->amount,
                'currency_code'  => $transfer->currency_code,
                'from_wallet_id' => $transfer->from_wallet_id,
                'to_wallet_id'   => $transfer->to_wallet_id,
            ], $correlationId);
        } catch (CurrencyMismatchException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (InsufficientBalanceException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (\DomainException $e) {
            return $this->fail('DOMAIN_ERROR', $e->getMessage(), 422, $correlationId);
        }
    }
}
