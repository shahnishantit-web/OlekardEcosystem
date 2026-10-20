<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Application\DTOs\FxQuoteRequestDTO;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxQuoteRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Enums\FxQuoteStatus;
use App\Modules\MultiCurrency\Domain\Events\FxQuoteIssuedEvent;
use App\Modules\MultiCurrency\Domain\Exceptions\FxRateNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\MultiCurrency\Infrastructure\Models\FxQuoteModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class FxQuoteService
{
    private const QUOTE_TTL_SECONDS = 60;

    public function __construct(
        private readonly FxRateRepositoryInterface         $fxRates,
        private readonly FxQuoteRepositoryInterface        $fxQuotes,
        private readonly CurrencyWalletRepositoryInterface $currencyWallets,
        private readonly LedgerModuleContract              $ledger,
        private readonly EventPublisherInterface           $events,
        private readonly AuditServiceInterface             $audit,
    ) {}

    /**
     * Preview FX fee without creating a quote record.
     *
     * @return array{from_currency_code: string, to_currency_code: string, from_amount: string, fee_amount: string, to_amount_estimate: string, rate: string, spread: string}
     */
    public function previewFee(string $fromCurrency, string $toCurrency, string $fromAmount): array
    {
        $rate = $this->fxRates->findActivePair($fromCurrency, $toCurrency);
        if ($rate === null) {
            throw new FxRateNotFoundException($fromCurrency, $toCurrency);
        }

        [$feeAmount, $toAmount] = $this->calculateAmounts($fromAmount, $rate->rate, $rate->spread);

        return [
            'from_currency_code' => strtoupper($fromCurrency),
            'to_currency_code'   => strtoupper($toCurrency),
            'from_amount'        => $fromAmount,
            'fee_amount'         => $feeAmount,
            'to_amount_estimate' => $toAmount,
            'rate'               => $rate->rate,
            'spread'             => $rate->spread,
        ];
    }

    /**
     * Request a locked FX quote (Phase 1 of conversion).
     */
    public function requestQuote(FxQuoteRequestDTO $dto): FxQuoteModel
    {
        // Idempotency: return existing quote for same key
        $existing = $this->fxQuotes->findByIdempotencyKey($dto->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $rate = $this->fxRates->findActivePair($dto->fromCurrencyCode, $dto->toCurrencyCode);
        if ($rate === null) {
            throw new FxRateNotFoundException($dto->fromCurrencyCode, $dto->toCurrencyCode);
        }

        // Validate customer has a source wallet with sufficient balance
        $sourceWallet = $this->currencyWallets->findByCustomerAndCurrency($dto->customerId, $dto->fromCurrencyCode);
        if ($sourceWallet === null) {
            throw new \DomainException("No [{$dto->fromCurrencyCode}] wallet found for this customer.");
        }

        $fromAmountMinor = (int) bcmul($dto->fromAmount, '100', 0);
        $available       = $this->ledger->getBalance($sourceWallet->pocket_ledger_account_reference);

        if ($available < $fromAmountMinor) {
            throw new InsufficientBalanceException();
        }

        [$feeAmount, $toAmount] = $this->calculateAmounts($dto->fromAmount, $rate->rate, $rate->spread);

        return DB::connection('platform')->transaction(function () use ($dto, $rate, $feeAmount, $toAmount): FxQuoteModel {
            $quote                  = new FxQuoteModel();
            $quote->id              = (string) Uuid::uuid7();
            $quote->customer_id     = $dto->customerId;
            $quote->from_currency_code = strtoupper($dto->fromCurrencyCode);
            $quote->to_currency_code   = strtoupper($dto->toCurrencyCode);
            $quote->from_amount     = $dto->fromAmount;
            $quote->to_amount       = $toAmount;
            $quote->fee_amount      = $feeAmount;
            $quote->rate            = $rate->rate;
            $quote->fx_rate_id      = $rate->id;
            $quote->status          = FxQuoteStatus::Quoted;
            $quote->expires_at      = now()->addSeconds(self::QUOTE_TTL_SECONDS);
            $quote->idempotency_key = $dto->idempotencyKey;
            $this->fxQuotes->save($quote);

            $this->events->publish(new FxQuoteIssuedEvent(
                customerId:       $dto->customerId,
                quoteId:          $quote->id,
                fromCurrencyCode: $quote->from_currency_code,
                toCurrencyCode:   $quote->to_currency_code,
                fromAmount:       $quote->from_amount,
                toAmount:         $quote->to_amount,
                expiresAt:        $quote->expires_at->toIso8601String(),
                occurredAt:       now()->toIso8601String(),
                correlationId:    $dto->correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'multi_currency.fx_quote.issued',
                actorId:       $dto->customerId,
                actorType:     'CUSTOMER',
                resourceType:  'fx_quote',
                resourceId:    $quote->id,
                correlationId: $dto->correlationId,
                outcome:       'success',
                afterState:    ['from' => $dto->fromCurrencyCode, 'to' => $dto->toCurrencyCode, 'from_amount' => $dto->fromAmount],
            ));

            return $quote;
        });
    }

    /**
     * @return array{0: string, 1: string} [fee_amount, to_amount]
     */
    private function calculateAmounts(string $fromAmount, string $rate, string $spread): array
    {
        $feeAmount = bcmul($fromAmount, $spread, 8);
        $netAmount = bcsub($fromAmount, $feeAmount, 8);
        $toAmount  = bcmul($netAmount, $rate, 8);

        return [
            bcadd($feeAmount, '0', 4),
            bcadd($toAmount, '0', 4),
        ];
    }
}
