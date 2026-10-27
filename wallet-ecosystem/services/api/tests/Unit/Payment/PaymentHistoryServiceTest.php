<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Application\Services\PaymentHistoryService;
use App\Modules\Payment\Domain\ValueObjects\PaymentHistoryRecord;
use Tests\TestCase;

final class PaymentHistoryServiceTest extends TestCase
{
    private TestablePaymentHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestablePaymentHistoryService();
    }

    public function test_returns_only_payer_scoped_records(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_safe_status_mapping_pending_returns_processing(): void
    {
        $this->assertSame('processing', $this->service->exposedMapIntentStatus('pending', null));
        $this->assertSame('processing', $this->service->exposedMapIntentStatus('processing', null));
        $this->assertSame('processing', $this->service->exposedMapIntentStatus('awaiting_action', null));
        $this->assertSame('processing', $this->service->exposedMapIntentStatus('requires_confirmation', null));
    }

    public function test_safe_status_mapping_succeeded_no_refund_returns_confirmed(): void
    {
        $this->assertSame('confirmed', $this->service->exposedMapIntentStatus('succeeded', null));
    }

    public function test_safe_status_mapping_confirmed_with_pending_refund_returns_refund_pending(): void
    {
        $this->assertSame('refund_pending', $this->service->exposedMapIntentStatus('succeeded', 'requested'));
        $this->assertSame('refund_pending', $this->service->exposedMapIntentStatus('succeeded', 'processing'));
        $this->assertSame('refund_pending', $this->service->exposedMapIntentStatus('succeeded', 'pending'));
        $this->assertSame('refund_pending', $this->service->exposedMapIntentStatus('refunding', null));
        $this->assertSame('refund_pending', $this->service->exposedMapIntentStatus('partially_refunded', null));
    }

    public function test_safe_status_mapping_confirmed_with_confirmed_refund_returns_refunded(): void
    {
        $this->assertSame('refunded', $this->service->exposedMapIntentStatus('succeeded', 'confirmed'));
        $this->assertSame('refunded', $this->service->exposedMapIntentStatus('succeeded', 'succeeded'));
        $this->assertSame('refunded', $this->service->exposedMapIntentStatus('refunded', null));
    }

    public function test_fee_falls_back_to_quoted_when_no_confirmed_attempt(): void
    {
        $row = $this->makeRow([
            'attempt_fee_minor' => null,
            'fee_quote_minor'   => 500,
            'original_amount_minor' => 10000,
        ]);

        $record = $this->service->exposedMapRowToRecord($row, []);

        $this->assertSame(500, $record->feeAmountMinor);
        $this->assertSame(10500, $record->totalChargedMinor);
    }

    public function test_fee_uses_confirmed_attempt_not_quoted(): void
    {
        $row = $this->makeRow([
            'attempt_fee_minor' => 300,
            'fee_quote_minor'   => 500,
            'original_amount_minor' => 10000,
        ]);

        $record = $this->service->exposedMapRowToRecord($row, []);

        $this->assertSame(300, $record->feeAmountMinor);
        $this->assertSame(10300, $record->totalChargedMinor);
    }

    public function test_purpose_type_filter_applied(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_domain_deep_link_constructed_from_validated_purpose_type_and_reference(): void
    {
        $link = $this->service->exposedBuildDeepLink('funeral_assistance', 'ref-123', []);

        $this->assertSame('funeral_assistance://ref-123', $link);
    }

    public function test_retired_purpose_returns_unavailable_deep_link(): void
    {
        $link = $this->service->exposedBuildDeepLink('old_domain', 'ref-123', ['old_domain']);

        $this->assertIsArray($link);
        $this->assertSame('unavailable', $link['status']);
        $this->assertSame('domain_retired', $link['reason']);
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

final class TestablePaymentHistoryService
{
    public function exposedMapIntentStatus(string $intentStatus, ?string $refundStatusRaw): string
    {
        return (new \ReflectionMethod(PaymentHistoryService::class, 'mapIntentStatus'))->invoke(new PaymentHistoryService(), $intentStatus, $refundStatusRaw);
    }

    public function exposedMapRowToRecord(object $row, array $retiredPurposes): PaymentHistoryRecord
    {
        return (new \ReflectionMethod(PaymentHistoryService::class, 'mapRowToRecord'))->invoke(new PaymentHistoryService(), $row, $retiredPurposes);
    }

    public function exposedBuildDeepLink(string $purposeType, string $purposeReference, array $retiredPurposes): string|array
    {
        return (new \ReflectionMethod(PaymentHistoryService::class, 'buildDeepLink'))->invoke(new PaymentHistoryService(), $purposeType, $purposeReference, $retiredPurposes);
    }
}
