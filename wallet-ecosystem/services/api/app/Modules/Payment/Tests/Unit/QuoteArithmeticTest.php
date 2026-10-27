<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use App\Modules\Payment\Application\Services\QuoteService;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\Enums\QuoteStatus;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class QuoteArithmeticTest extends TestCase
{
    private QuoteService $service;
    /** @var MockObject&PaymentQuoteRepositoryInterface */
    private MockObject $repo;
    /** @var MockObject&AuditServiceInterface */
    private MockObject $audit;
    /** @var MockObject&ConfigurationServiceInterface */
    private MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = $this->createMock(PaymentQuoteRepositoryInterface::class);
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturn('300');

        $this->service = new QuoteService($this->repo, $this->audit, $this->config);
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

    private function makeIntent(): PaymentIntentModel
    {
        $intent = new PaymentIntentModel();
        $intent->id              = 'intent-001';
        $intent->payer_actor_id  = 'actor-001';
        $intent->currency_code   = 'BWP';
        return $intent;
    }

    private function makeFeeResult(int $feeMinor): FeeCalculationResult
    {
        return new FeeCalculationResult(
            feeAmountMinor:    $feeMinor,
            feeRuleId:         null,
            feeType:           null,
            hasApplicableRule: $feeMinor > 0,
            minFeeApplied:     false,
            maxFeeApplied:     false,
        );
    }

    public function test_total_equals_base_plus_fee(): void
    {
        $this->repo->method('findActiveForIntent')->willReturn(null);
        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $this->audit->method('record');

        $quote = $this->service->createQuote(
            $this->makeIntent(),
            $this->makeObligation(10000),
            $this->makeFeeResult(200),
            ['rounding_mode' => 'floor'],
            'wallet',
            'corr-001',
        );

        $this->assertSame(10000, $quote->base_amount_minor);
        $this->assertSame(200,   $quote->fee_amount_minor);
        $this->assertSame(10200, $quote->total_amount_minor);
    }

    public function test_zero_fee_is_accepted(): void
    {
        $this->repo->method('findActiveForIntent')->willReturn(null);
        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $this->audit->method('record');

        $quote = $this->service->createQuote(
            $this->makeIntent(),
            $this->makeObligation(5000),
            $this->makeFeeResult(0),
            ['rounding_mode' => 'floor'],
            'wallet',
            'corr-002',
        );

        $this->assertSame(5000, $quote->base_amount_minor);
        $this->assertSame(0,    $quote->fee_amount_minor);
        $this->assertSame(5000, $quote->total_amount_minor);
    }

    public function test_quote_version_starts_at_one(): void
    {
        $this->repo->method('findActiveForIntent')->willReturn(null);
        $this->repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $this->audit->method('record');

        $quote = $this->service->createQuote(
            $this->makeIntent(),
            $this->makeObligation(),
            $this->makeFeeResult(100),
            [],
            'wallet',
            'corr-003',
        );

        $this->assertSame(1, $quote->version);
    }

    public function test_idempotency_returns_existing_active_v1_quote(): void
    {
        $existing = new PaymentQuoteModel();
        $existing->id = 'existing-quote-id';
        $existing->version = 1;
        $existing->quote_status = QuoteStatus::Active;
        $existing->base_amount_minor = 10000;
        $existing->fee_amount_minor = 100;
        $existing->total_amount_minor = 10100;

        $this->repo->method('findActiveForIntent')->willReturn($existing);
        $this->repo->expects($this->never())->method('save');

        $result = $this->service->createQuote(
            $this->makeIntent(),
            $this->makeObligation(),
            $this->makeFeeResult(200),
            [],
            'wallet',
            'corr-idem',
        );

        $this->assertSame('existing-quote-id', $result->id);
        $this->assertSame(10100, $result->total_amount_minor);
    }
}
