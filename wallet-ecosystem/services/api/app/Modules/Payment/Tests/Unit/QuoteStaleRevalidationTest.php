<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\QuoteService;
use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
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
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class QuoteStaleRevalidationTest extends TestCase
{
    /** @var MockObject&PaymentQuoteRepositoryInterface */
    private MockObject $repo;
    /** @var MockObject&AuditServiceInterface */
    private MockObject $audit;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;
    /** @var MockObject&FeesModuleContract */
    private MockObject $fees;

    private QuoteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = $this->createMock(PaymentQuoteRepositoryInterface::class);
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn('300');
        $this->fees   = $this->createMock(FeesModuleContract::class);

        $this->service = new QuoteService($this->repo, $this->audit, $this->config);
    }

    private function makeIntent(string $payerActorId = 'actor-001'): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id                = 'intent-001';
        $intent->purpose_type      = 'bill';
        $intent->purpose_reference = 'ref-001';
        $intent->payer_actor_id    = $payerActorId;
        $intent->currency_code     = 'BWP';
        return $intent;
    }

    private function makeActiveQuote(int $totalMinor = 10200, int $version = 1): PaymentQuoteModel
    {
        $quote = new PaymentQuoteModel();
        $quote->id                 = 'quote-001';
        $quote->payment_intent_id  = 'intent-001';
        $quote->version            = $version;
        $quote->quote_status       = QuoteStatus::Active;
        $quote->base_amount_minor  = 10000;
        $quote->fee_amount_minor   = $totalMinor - 10000;
        $quote->total_amount_minor = $totalMinor;
        $quote->currency_code      = 'BWP';
        $quote->provider_code      = 'wallet';
        $quote->expires_at         = now()->subMinutes(1); // already expired
        $quote->fee_rule_snapshot  = [];
        $quote->obligation_snapshot = [];
        return $quote;
    }

    private function makeObligation(int $amountMinor = 10000): EnrichedPayableObligation
    {
        return new EnrichedPayableObligation(
            amountMinor:    $amountMinor,
            currencyCode:   'BWP',
            organisationId: 'org-001',
            payerActorId:   'actor-001',
        );
    }

    private function makeRegistry(BusinessPurposeResolverInterface $resolver): BusinessPurposeResolverRegistry
    {
        $registry = new BusinessPurposeResolverRegistry();
        $registry->register('bill', $resolver);
        return $registry;
    }

    /**
     * (a) Fresh quote path: consumeQuote directly marks the quote as Consumed and audits.
     *
     * When the quote is not expired, the orchestration service skips revalidation and calls
     * consumeQuote directly. This test verifies consumeQuote sets status = Consumed and records
     * the audit event so that the "fresh quote → attempt proceeds" path is safe.
     */
    public function test_fresh_quote_consumed_without_revalidation(): void
    {
        $quote = new PaymentQuoteModel();
        $quote->id           = 'quote-fresh';
        $quote->version      = 1;
        $quote->quote_status = QuoteStatus::Active;
        $quote->expires_at   = now()->addMinutes(5); // still fresh

        $this->repo->expects($this->once())->method('save')
            ->with($this->callback(fn ($q) => $q->quote_status === QuoteStatus::Consumed));

        $this->audit->expects($this->once())->method('record')
            ->with($this->callback(fn ($e) => $e->action === 'payment.quote.consumed'));

        $this->service->consumeQuote($quote, 'corr-001');

        $this->assertSame(QuoteStatus::Consumed, $quote->quote_status);
    }

    /**
     * (b) Expired quote, same total → refreshQuote called silently, returns true, no exception.
     */
    public function test_expired_quote_same_total_silently_refreshed(): void
    {
        $activeQuote = $this->makeActiveQuote(totalMinor: 10200);

        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->makeObligation(10000));
        $registry = $this->makeRegistry($resolver);

        // Fee = 200 → new total = 10200 = old total → silent refresh
        $this->fees->method('calculateFee')->willReturn(
            new FeeCalculationResult(200, null, null, false, false, false)
        );

        // Expect: supersede old + save new (2 saves: supersede + new quote from refreshQuote)
        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $this->audit->method('record');

        $result = $this->service->revalidateQuote(
            $this->makeIntent(),
            $activeQuote,
            $registry,
            $this->fees,
            'corr-refresh',
        );

        $this->assertTrue($result);
        $this->assertSame(QuoteStatus::Superseded, $activeQuote->quote_status);
    }

    /**
     * (c) Expired quote, different total → QuoteStaleConfirmRequiredException thrown with new quote.
     */
    public function test_expired_quote_different_total_throws_stale_exception(): void
    {
        $activeQuote = $this->makeActiveQuote(totalMinor: 10200); // old total = 10200

        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->makeObligation(10000));
        $registry = $this->makeRegistry($resolver);

        // Fee = 500 → new total = 10500 ≠ 10200
        $this->fees->method('calculateFee')->willReturn(
            new FeeCalculationResult(500, null, null, false, false, false)
        );

        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $this->audit->method('record');

        $this->expectException(QuoteStaleConfirmRequiredException::class);

        try {
            $this->service->revalidateQuote(
                $this->makeIntent(),
                $activeQuote,
                $registry,
                $this->fees,
                'corr-changed',
            );
        } catch (QuoteStaleConfirmRequiredException $e) {
            $this->assertSame(10200, $e->previousTotalMinor);
            $this->assertSame(10500, $e->newTotalMinor);
            $this->assertSame(QuoteStatus::Active, $e->newQuote->quote_status);
            $this->assertSame(QuoteStatus::Superseded, $activeQuote->quote_status);
            throw $e;
        }
    }

    /**
     * (d) Expired quote, purpose gone → PurposeNoLongerPayableException thrown, intent cancelled.
     */
    public function test_expired_quote_purpose_gone_cancels_quote_and_throws(): void
    {
        $activeQuote = $this->makeActiveQuote();

        $resolver = $this->createMock(BusinessPurposeResolverInterface::class);
        $resolver->method('resolve')->willThrowException(
            new PurposeNotPayableException('bill', 'ref-001', 'resource no longer active')
        );
        $registry = $this->makeRegistry($resolver);

        $saveCount = 0;
        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q) use (&$saveCount): void {
            $saveCount++;
        });

        $auditActions = [];
        $this->audit->method('record')->willReturnCallback(function ($e) use (&$auditActions): void {
            $auditActions[] = $e->action;
        });

        $this->expectException(PurposeNoLongerPayableException::class);

        try {
            $this->service->revalidateQuote(
                $this->makeIntent(),
                $activeQuote,
                $registry,
                $this->fees,
                'corr-gone',
            );
        } catch (PurposeNoLongerPayableException $e) {
            $this->assertSame(QuoteStatus::Cancelled, $activeQuote->quote_status);
            $this->assertContains('payment.quote.cancelled', $auditActions);
            $this->assertContains('payment.intent.cancelled', $auditActions);
            throw $e;
        }
    }
}
