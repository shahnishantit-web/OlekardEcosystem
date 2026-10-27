<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\Contracts\PayoutRequestRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\PayableBalance;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T031: PayoutBalanceService unit tests.
 *
 * Pure-logic tests for payable balance computation.
 * DB-integration (query join chains) requires Docker.
 */
final class PayoutBalanceServiceTest extends TestCase
{
    // ── PayableBalance value object ──────────────────────────────────────────

    public function test_payable_balance_formula_subtracts_all_deductions(): void
    {
        $balance = new PayableBalance(
            fundReferenceId:           'fund-001',
            currencyCode:              'BWP',
            custodyBalanceMinor:       100_000,
            confirmedRefundsMinor:     10_000,
            openDisputeHoldsMinor:     5_000,
            existingReservationsMinor: 20_000,
            computedAt:                now(),
        );

        // 100_000 - 10_000 - 5_000 - 20_000 = 65_000
        $this->assertSame(65_000, $balance->payableMinor());
    }

    public function test_payable_balance_never_goes_negative(): void
    {
        $balance = new PayableBalance(
            fundReferenceId:           'fund-002',
            currencyCode:              'ZAR',
            custodyBalanceMinor:       5_000,
            confirmedRefundsMinor:     6_000,
            openDisputeHoldsMinor:     0,
            existingReservationsMinor: 0,
            computedAt:                now(),
        );

        // 5_000 - 6_000 = -1_000 → clamped to 0
        $this->assertSame(0, $balance->payableMinor());
    }

    public function test_payable_balance_to_array_includes_all_fields(): void
    {
        $balance = new PayableBalance(
            fundReferenceId:           'fund-003',
            currencyCode:              'BWP',
            custodyBalanceMinor:       50_000,
            confirmedRefundsMinor:     1_000,
            openDisputeHoldsMinor:     2_000,
            existingReservationsMinor: 3_000,
            computedAt:                now(),
        );

        $array = $balance->toArray();

        $this->assertArrayHasKey('fund_reference_id', $array);
        $this->assertArrayHasKey('custody_balance_minor', $array);
        $this->assertArrayHasKey('confirmed_refunds_minor', $array);
        $this->assertArrayHasKey('open_dispute_holds_minor', $array);
        $this->assertArrayHasKey('existing_reservations_minor', $array);
        $this->assertArrayHasKey('payable_minor', $array);
        $this->assertArrayHasKey('computed_at', $array);
        $this->assertSame(44_000, $array['payable_minor']);
    }

    /**
     * Verifies that the balance service does NOT reference a non-existent fund_reference_id
     * column on payment_refunds; confirmed refunds must be joined through payment_intents.purpose_reference.
     *
     * This test documents the correct query path (U1 remediation from analyze phase).
     * The join chain is: payment_refunds → payment_intents (via payment_intent_id)
     * WHERE payment_intents.purpose_reference = $fundReferenceId
     * NOT: payment_refunds WHERE fund_reference_id = $fundReferenceId (column does not exist)
     */
    public function test_refund_query_joins_through_payment_intents_purpose_reference_not_direct_column(): void
    {
        // Structural assertion: PayoutBalanceService must not reference a fund_reference_id
        // column directly on payment_refunds. Read the service source and verify the join chain.
        $serviceSource = file_get_contents(
            app_path('Modules/Payment/Application/Services/PayoutBalanceService.php'),
        );

        // Must use the join chain
        $this->assertStringContainsString(
            "join('payment_intents', 'payment_refunds.payment_intent_id'",
            $serviceSource,
            'PayoutBalanceService must join payment_intents to reach purpose_reference',
        );
        $this->assertStringContainsString(
            "'payment_intents.purpose_reference', \$fundReferenceId",
            $serviceSource,
            'PayoutBalanceService must filter by payment_intents.purpose_reference',
        );

        // Must NOT query a non-existent column directly on payment_refunds
        $this->assertStringNotContainsString(
            "payment_refunds.fund_reference_id",
            $serviceSource,
            'payment_refunds does not have a fund_reference_id column — never query it directly',
        );
    }

    public function test_payable_balance_with_zero_deductions_equals_custody_balance(): void
    {
        $balance = new PayableBalance(
            fundReferenceId:           'fund-004',
            currencyCode:              'BWP',
            custodyBalanceMinor:       200_000,
            confirmedRefundsMinor:     0,
            openDisputeHoldsMinor:     0,
            existingReservationsMinor: 0,
            computedAt:                now(),
        );

        $this->assertSame(200_000, $balance->payableMinor());
    }
}
