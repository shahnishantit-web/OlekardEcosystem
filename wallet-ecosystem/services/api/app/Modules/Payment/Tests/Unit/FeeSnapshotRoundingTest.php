<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;
use App\Modules\Payment\Application\Services\QuoteService;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

final class FeeSnapshotRoundingTest extends TestCase
{
    private function makeService(): QuoteService
    {
        $repo   = $this->createMock(PaymentQuoteRepositoryInterface::class);
        $repo->method('findActiveForIntent')->willReturn(null);
        $repo->method('save')->willReturnCallback(function (PaymentQuoteModel $q): void {});
        $audit  = $this->createMock(AuditServiceInterface::class);
        $audit->method('record');
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturn('300');
        return new QuoteService($repo, $audit, $config);
    }

    private function makeObligation(int $amount = 10000): EnrichedPayableObligation
    {
        return new EnrichedPayableObligation(
            amountMinor: $amount, currencyCode: 'BWP',
            organisationId: 'org-001', payerActorId: 'actor-001',
        );
    }

    private function makeIntent(): PaymentIntentModel
    {
        $i = new PaymentIntentModel();
        $i->id = 'intent-001'; $i->payer_actor_id = 'actor-001'; $i->currency_code = 'BWP';
        return $i;
    }

    public function test_fee_snapshot_captures_rounding_mode_floor(): void
    {
        $feeResult = new FeeCalculationResult(150, 'rule-1', 'percentage', true, false, false);
        $details   = ['rounding_mode' => 'floor', 'fee_type' => 'percentage', 'rate_basis_points' => 200];

        $quote = $this->makeService()->createQuote(
            $this->makeIntent(), $this->makeObligation(), $feeResult, $details, 'wallet', 'corr'
        );

        $this->assertSame('floor', $quote->fee_rule_snapshot['rounding_mode']);
        $this->assertSame(150,     $quote->fee_rule_snapshot['computed_fee_minor']);
    }

    public function test_fee_snapshot_captures_rounding_mode_round_half_up(): void
    {
        $feeResult = new FeeCalculationResult(155, 'rule-2', 'percentage', true, false, false);
        $details   = ['rounding_mode' => 'round_half_up', 'fee_type' => 'percentage'];

        $quote = $this->makeService()->createQuote(
            $this->makeIntent(), $this->makeObligation(), $feeResult, $details, 'wallet', 'corr'
        );

        $this->assertSame('round_half_up', $quote->fee_rule_snapshot['rounding_mode']);
    }

    public function test_min_fee_applied_captured_in_snapshot(): void
    {
        $feeResult = new FeeCalculationResult(
            feeAmountMinor: 50, feeRuleId: 'rule-3', feeType: 'percentage',
            hasApplicableRule: true, minFeeApplied: true, maxFeeApplied: false
        );

        $quote = $this->makeService()->createQuote(
            $this->makeIntent(), $this->makeObligation(), $feeResult, [], 'wallet', 'corr'
        );

        $this->assertTrue($quote->fee_rule_snapshot['min_fee_applied']);
        $this->assertFalse($quote->fee_rule_snapshot['max_fee_applied']);
    }

    public function test_max_fee_cap_captured_in_snapshot(): void
    {
        $feeResult = new FeeCalculationResult(
            feeAmountMinor: 5000, feeRuleId: 'rule-4', feeType: 'percentage',
            hasApplicableRule: true, minFeeApplied: false, maxFeeApplied: true
        );

        $quote = $this->makeService()->createQuote(
            $this->makeIntent(), $this->makeObligation(100000), $feeResult, [], 'wallet', 'corr'
        );

        $this->assertFalse($quote->fee_rule_snapshot['min_fee_applied']);
        $this->assertTrue($quote->fee_rule_snapshot['max_fee_applied']);
    }

    public function test_computation_inputs_recorded_in_snapshot(): void
    {
        $feeResult = new FeeCalculationResult(100, null, null, false, false, false);

        $quote = $this->makeService()->createQuote(
            $this->makeIntent(), $this->makeObligation(10000), $feeResult, [], 'wallet', 'corr'
        );

        $inputs = $quote->fee_rule_snapshot['computation_inputs'];
        $this->assertSame(10000,     $inputs['amount_minor']);
        $this->assertSame('BWP',     $inputs['currency_code']);
        $this->assertSame('payment', $inputs['transaction_type']);
        $this->assertSame('customer', $inputs['subject_type']);
    }
}
