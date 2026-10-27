<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use Tests\TestCase;

final class PaymentHistoryDeepLinkTest extends TestCase
{
    private TestablePaymentHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestablePaymentHistoryService();
    }

    public function test_deep_link_format_is_purpose_type_colon_slash_slash_reference(): void
    {
        $link = $this->service->exposedBuildDeepLink('funeral_assistance', 'claim-12345', []);

        $this->assertSame('funeral_assistance://claim-12345', $link);
    }

    public function test_deep_link_uses_exact_purpose_type_and_reference(): void
    {
        $link = $this->service->exposedBuildDeepLink('wallet_transfer', 'txn-abc-999', []);

        $this->assertSame('wallet_transfer://txn-abc-999', $link);
    }

    public function test_retired_purpose_returns_unavailable_object(): void
    {
        $link = $this->service->exposedBuildDeepLink('old_payments', 'ref-999', ['old_payments', 'legacy_transfer']);

        $this->assertIsArray($link);
        $this->assertSame('unavailable', $link['status']);
        $this->assertSame('domain_retired', $link['reason']);
    }

    public function test_non_retired_purpose_not_in_retired_list_returns_link(): void
    {
        $link = $this->service->exposedBuildDeepLink('active_domain', 'ref-999', ['old_payments']);

        $this->assertIsString($link);
        $this->assertStringStartsWith('active_domain://', (string) $link);
    }

    public function test_refund_eligible_true_when_confirmed_with_no_active_refund(): void
    {
        $row = $this->makeRow(['intent_status' => 'succeeded', 'refund_status_raw' => null]);

        $record = $this->service->exposedMapRowToRecord($row, []);

        $this->assertTrue($record->refundEligible);
    }

    public function test_refund_eligible_false_when_refund_in_progress(): void
    {
        $row = $this->makeRow(['intent_status' => 'succeeded', 'refund_status_raw' => 'processing']);

        $record = $this->service->exposedMapRowToRecord($row, []);

        $this->assertFalse($record->refundEligible);
    }

    public function test_refund_eligible_false_when_intent_not_succeeded(): void
    {
        $row = $this->makeRow(['intent_status' => 'pending', 'refund_status_raw' => null]);

        $record = $this->service->exposedMapRowToRecord($row, []);

        $this->assertFalse($record->refundEligible);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeRow(array $overrides): object
    {
        return (object) array_merge([
            'payment_reference'    => 'pi-test-001',
            'purpose_type'         => 'wallet_transfer',
            'purpose_reference'    => 'ref-001',
            'organisation_id'      => 'org-001',
            'payer_actor_id'       => 'user-001',
            'currency_code'        => 'BWP',
            'original_amount_minor' => 10000,
            'intent_status'        => 'succeeded',
            'fee_quote_minor'      => 0,
            'created_at'           => now()->toIso8601String(),
            'attempt_fee_minor'    => null,
            'attempt_provider_code' => null,
            'attempt_confirmed_at' => null,
            'refund_amount_minor'  => null,
            'refund_status_raw'    => null,
        ], $overrides);
    }
}
