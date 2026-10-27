<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\Enums\QuoteStatus;
use App\Modules\Payment\Domain\Exceptions\PurposeNoLongerPayableException;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\Exceptions\QuoteStaleConfirmRequiredException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Ramsey\Uuid\Uuid;

final class QuoteService
{
    public function __construct(
        private readonly PaymentQuoteRepositoryInterface $quoteRepository,
        private readonly AuditServiceInterface           $audit,
        private readonly ConfigurationServiceInterface   $config,
    ) {
    }

    /**
     * Create (or idempotently return) the first quote for a payment intent.
     *
     * Idempotency guard: if an active version-1 quote already exists for a pending intent,
     * return it rather than creating a duplicate.
     *
     * @param array<string, mixed> $feeRuleDetails pre-fetched from FeeRuleModel (or zero-fee defaults)
     */
    public function createQuote(
        PaymentIntentModel        $intent,
        EnrichedPayableObligation $obligation,
        FeeCalculationResult      $feeResult,
        array                     $feeRuleDetails,
        string                    $providerCode,
        string                    $correlationId,
    ): PaymentQuoteModel {
        // Idempotency: return existing active version-1 quote for a pending intent
        $existing = $this->quoteRepository->findActiveForIntent($intent->id);
        if ($existing !== null && $existing->version === 1) {
            return $existing;
        }

        $base  = $obligation->amountMinor;
        $fee   = $feeResult->feeAmountMinor;
        $total = $base + $fee;

        if ($total !== $base + $fee) {
            throw new \DomainException(
                "Quote arithmetic invariant violated: base({$base}) + fee({$fee}) != total({$total})."
            );
        }

        $ttlSeconds = (int) ($this->config->getOrDefault('payment.quote_ttl_seconds', 300) ?? 300);
        $expiresAt  = now()->addSeconds($ttlSeconds);

        $quote = new PaymentQuoteModel();
        $quote->id                 = (string) Uuid::uuid7();
        $quote->payment_intent_id  = $intent->id;
        $quote->version            = 1;
        $quote->quote_status       = QuoteStatus::Active;
        $quote->base_amount_minor  = $base;
        $quote->fee_amount_minor   = $fee;
        $quote->total_amount_minor = $total;
        $quote->currency_code      = $obligation->currencyCode;
        $quote->provider_code      = $providerCode;
        $quote->rounding_mode      = $feeRuleDetails['rounding_mode'] ?? 'floor';
        $quote->fee_rule_snapshot  = $this->buildFeeSnapshot($feeResult, $feeRuleDetails, $obligation);
        $quote->obligation_snapshot = $this->buildObligationSnapshot($obligation);
        $quote->expires_at         = $expiresAt;
        $quote->created_at         = now();

        $this->quoteRepository->save($quote);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.created',
            actorId:       $obligation->payerActorId,
            actorType:     'customer',
            resourceType:  'payment_quote',
            resourceId:    $quote->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    [
                'version'            => 1,
                'total_amount_minor' => $total,
                'provider_code'      => $providerCode,
            ],
        ));

        return $quote;
    }

    /**
     * Supersede an active quote (mark it superseded, audit the event).
     */
    public function supersede(PaymentQuoteModel $old, string $correlationId): void
    {
        $old->quote_status  = QuoteStatus::Superseded;
        $old->superseded_at = now();
        $this->quoteRepository->save($old);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.superseded',
            actorId:       'system',
            actorType:     'system',
            resourceType:  'payment_quote',
            resourceId:    $old->id,
            correlationId: $correlationId,
            outcome:       'superseded',
            afterState:    ['version' => $old->version, 'superseded_at' => now()->toIso8601String()],
        ));
    }

    /**
     * Silently refresh a quote: supersede the old row and create a new version with same total.
     *
     * @param array<string, mixed> $feeRuleDetails
     */
    public function refreshQuote(
        PaymentQuoteModel        $old,
        EnrichedPayableObligation $obligation,
        FeeCalculationResult     $feeResult,
        array                    $feeRuleDetails,
        string                   $correlationId,
    ): PaymentQuoteModel {
        $this->supersede($old, $correlationId);

        $ttlSeconds = (int) ($this->config->getOrDefault('payment.quote_ttl_seconds', 300) ?? 300);

        $quote = new PaymentQuoteModel();
        $quote->id                 = (string) Uuid::uuid7();
        $quote->payment_intent_id  = $old->payment_intent_id;
        $quote->version            = $old->version + 1;
        $quote->quote_status       = QuoteStatus::Active;
        $quote->base_amount_minor  = $obligation->amountMinor;
        $quote->fee_amount_minor   = $feeResult->feeAmountMinor;
        $quote->total_amount_minor = $obligation->amountMinor + $feeResult->feeAmountMinor;
        $quote->currency_code      = $obligation->currencyCode;
        $quote->provider_code      = $old->provider_code;
        $quote->rounding_mode      = $feeRuleDetails['rounding_mode'] ?? 'floor';
        $quote->fee_rule_snapshot  = $this->buildFeeSnapshot($feeResult, $feeRuleDetails, $obligation);
        $quote->obligation_snapshot = $this->buildObligationSnapshot($obligation);
        $quote->expires_at         = now()->addSeconds($ttlSeconds);
        $quote->created_at         = now();

        $this->quoteRepository->save($quote);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.created',
            actorId:       $obligation->payerActorId,
            actorType:     'customer',
            resourceType:  'payment_quote',
            resourceId:    $quote->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    ['version' => $quote->version, 'total_amount_minor' => $quote->total_amount_minor],
        ));

        return $quote;
    }

    /**
     * Re-validate a stale quote before allowing a payment attempt.
     *
     * - If purpose no longer payable → cancel intent/quote, throw PurposeNoLongerPayableException
     * - If total unchanged → refresh silently, return unchanged marker
     * - If total changed → supersede old, create new, throw QuoteStaleConfirmRequiredException
     */
    public function revalidateQuote(
        PaymentIntentModel             $intent,
        PaymentQuoteModel              $activeQuote,
        BusinessPurposeResolverRegistry $registry,
        FeesModuleContract             $fees,
        string                         $correlationId,
    ): bool {
        try {
            $resolver   = $registry->resolverFor($intent->purpose_type);
            $obligation = $resolver->resolve($intent->purpose_reference, $intent->payer_actor_id, $correlationId);
        } catch (PurposeNotPayableException $e) {
            $this->cancelQuote($activeQuote, $correlationId);

            $this->audit->record(new AuditEventData(
                action:        'payment.intent.cancelled',
                actorId:       $intent->payer_actor_id,
                actorType:     'system',
                resourceType:  'payment_intent',
                resourceId:    $intent->id,
                correlationId: $correlationId,
                outcome:       'cancelled',
                afterState:    ['reason' => 'purpose_no_longer_payable', 'detail' => $e->getMessage()],
            ));

            throw new PurposeNoLongerPayableException();
        }

        $feeResult      = $fees->calculateFee('payment', $obligation->amountMinor, $obligation->currencyCode, 'customer', $obligation->payerActorId);
        $feeRuleDetails = $this->fetchFeeRuleDetails($feeResult);
        $newTotal       = $obligation->amountMinor + $feeResult->feeAmountMinor;
        $oldTotal       = $activeQuote->total_amount_minor;

        if ($newTotal === $oldTotal) {
            $this->refreshQuote($activeQuote, $obligation, $feeResult, $feeRuleDetails, $correlationId);
            return true;
        }

        // Total changed — supersede old, create new, block execution
        $this->supersede($activeQuote, $correlationId);

        $ttlSeconds = (int) ($this->config->getOrDefault('payment.quote_ttl_seconds', 300) ?? 300);
        $newQuote = new PaymentQuoteModel();
        $newQuote->id                 = (string) Uuid::uuid7();
        $newQuote->payment_intent_id  = $intent->id;
        $newQuote->version            = $activeQuote->version + 1;
        $newQuote->quote_status       = QuoteStatus::Active;
        $newQuote->base_amount_minor  = $obligation->amountMinor;
        $newQuote->fee_amount_minor   = $feeResult->feeAmountMinor;
        $newQuote->total_amount_minor = $newTotal;
        $newQuote->currency_code      = $obligation->currencyCode;
        $newQuote->provider_code      = $activeQuote->provider_code;
        $newQuote->rounding_mode      = $feeRuleDetails['rounding_mode'] ?? 'floor';
        $newQuote->fee_rule_snapshot  = $this->buildFeeSnapshot($feeResult, $feeRuleDetails, $obligation);
        $newQuote->obligation_snapshot = $this->buildObligationSnapshot($obligation);
        $newQuote->expires_at         = now()->addSeconds($ttlSeconds);
        $newQuote->created_at         = now();
        $this->quoteRepository->save($newQuote);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.created',
            actorId:       $obligation->payerActorId,
            actorType:     'system',
            resourceType:  'payment_quote',
            resourceId:    $newQuote->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    ['version' => $newQuote->version, 'total_amount_minor' => $newTotal],
        ));

        throw new QuoteStaleConfirmRequiredException($newQuote, $oldTotal, $newTotal);
    }

    /**
     * Mark a quote as consumed when a payment attempt is initiated.
     */
    public function consumeQuote(PaymentQuoteModel $quote, string $correlationId): void
    {
        $quote->quote_status = QuoteStatus::Consumed;
        $quote->consumed_at  = now();
        $this->quoteRepository->save($quote);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.consumed',
            actorId:       'system',
            actorType:     'system',
            resourceType:  'payment_quote',
            resourceId:    $quote->id,
            correlationId: $correlationId,
            outcome:       'consumed',
            afterState:    ['version' => $quote->version, 'consumed_at' => now()->toIso8601String()],
        ));
    }

    /**
     * Cancel all active quotes for an intent (intent cancelled or expired).
     */
    public function cancelQuotesForIntent(string $intentId, string $correlationId): void
    {
        $quotes = $this->quoteRepository->findAllForIntent($intentId);
        foreach ($quotes as $quote) {
            if (! $quote->quote_status->isTerminal()) {
                $this->cancelQuote($quote, $correlationId);
            }
        }
    }

    private function cancelQuote(PaymentQuoteModel $quote, string $correlationId): void
    {
        $quote->quote_status = QuoteStatus::Cancelled;
        $quote->cancelled_at = now();
        $this->quoteRepository->save($quote);

        $this->audit->record(new AuditEventData(
            action:        'payment.quote.cancelled',
            actorId:       'system',
            actorType:     'system',
            resourceType:  'payment_quote',
            resourceId:    $quote->id,
            correlationId: $correlationId,
            outcome:       'cancelled',
            afterState:    ['version' => $quote->version, 'cancelled_at' => now()->toIso8601String()],
        ));
    }

    /**
     * Build a self-contained fee rule snapshot JSONB.
     *
     * @param array<string, mixed> $feeRuleDetails
     * @return array<string, mixed>
     */
    private function buildFeeSnapshot(
        FeeCalculationResult      $feeResult,
        array                     $feeRuleDetails,
        EnrichedPayableObligation $obligation,
    ): array {
        return [
            'rule_id'           => $feeRuleDetails['rule_id'] ?? null,
            'rule_name'         => $feeRuleDetails['rule_name'] ?? null,
            'fee_type'          => $feeRuleDetails['fee_type'] ?? 'none',
            'rate_basis_points' => $feeRuleDetails['rate_basis_points'] ?? null,
            'flat_amount_minor' => $feeRuleDetails['flat_amount_minor'] ?? null,
            'minimum_fee_minor' => $feeRuleDetails['minimum_fee_minor'] ?? 0,
            'maximum_fee_minor' => $feeRuleDetails['maximum_fee_minor'] ?? null,
            'rounding_mode'     => $feeRuleDetails['rounding_mode'] ?? 'floor',
            'effective_date'    => $feeRuleDetails['effective_date'] ?? null,
            'computed_fee_minor' => $feeResult->feeAmountMinor,
            'min_fee_applied'   => $feeResult->minFeeApplied,
            'max_fee_applied'   => $feeResult->maxFeeApplied,
            'computation_inputs' => [
                'amount_minor'     => $obligation->amountMinor,
                'currency_code'    => $obligation->currencyCode,
                'transaction_type' => 'payment',
                'subject_type'     => 'customer',
            ],
        ];
    }

    /**
     * Build an obligation snapshot for audit/dispute purposes.
     *
     * @return array<string, mixed>
     */
    private function buildObligationSnapshot(EnrichedPayableObligation $obligation): array
    {
        return [
            'amount_minor'            => $obligation->amountMinor,
            'currency_code'           => $obligation->currencyCode,
            'organisation_id'         => $obligation->organisationId,
            'payer_actor_id'          => $obligation->payerActorId,
            'expires_at'              => $obligation->expiresAt?->format(\DateTimeInterface::ATOM),
            'beneficiary_allocations' => $obligation->beneficiaryAllocations,
        ];
    }

    /**
     * Fetch fee rule details for the snapshot. Returns zero-fee defaults when no rule applies.
     *
     * @return array<string, mixed>
     */
    public function fetchFeeRuleDetails(FeeCalculationResult $feeResult): array
    {
        if ($feeResult->feeRuleId === null) {
            return [
                'rule_id'           => null,
                'rule_name'         => null,
                'fee_type'          => 'none',
                'rate_basis_points' => null,
                'flat_amount_minor' => null,
                'minimum_fee_minor' => 0,
                'maximum_fee_minor' => null,
                'rounding_mode'     => 'floor',
                'effective_date'    => null,
            ];
        }

        $rule = FeeRuleModel::query()->find($feeResult->feeRuleId);

        if ($rule === null) {
            return [
                'rule_id'           => $feeResult->feeRuleId,
                'rule_name'         => null,
                'fee_type'          => $feeResult->feeType ?? 'unknown',
                'rate_basis_points' => null,
                'flat_amount_minor' => null,
                'minimum_fee_minor' => 0,
                'maximum_fee_minor' => null,
                'rounding_mode'     => 'floor',
                'effective_date'    => null,
            ];
        }

        $rateBasisPoints = null;
        $flatAmountMinor = null;

        if ($rule->fee_type->value === 'percentage' && $rule->rate !== null) {
            $rateBasisPoints = (int) round($rule->rate * 100);
        } elseif ($rule->fee_type->value === 'fixed') {
            $flatAmountMinor = $rule->amount_minor;
        }

        return [
            'rule_id'           => $rule->id,
            'rule_name'         => $rule->id,
            'fee_type'          => $rule->fee_type->value,
            'rate_basis_points' => $rateBasisPoints,
            'flat_amount_minor' => $flatAmountMinor,
            'minimum_fee_minor' => $rule->min_fee_minor ?? 0,
            'maximum_fee_minor' => $rule->max_fee_minor,
            'rounding_mode'     => 'floor',
            'effective_date'    => $rule->valid_from?->toDateString(),
        ];
    }
}
