<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Exceptions\ResourceNotFoundException;
use App\Modules\Payment\Application\Services\ReceiptService;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\ValueObjects\ReceiptRecord;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Tests\TestCase;

final class ReceiptServiceTest extends TestCase
{
    public function test_compile_returns_fee_from_confirmed_attempt_not_quoted_fee(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_compile_throws_not_found_for_missing_intent(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_compile_throws_forbidden_when_requestor_is_not_payer(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_compile_throws_not_found_for_failed_intent(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_refund_summary_shows_pending_not_confirmed_for_in_flight_refund(): void
    {
        $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
    }

    public function test_receipt_does_not_expose_raw_provider_secrets(): void
    {
        $intent = new PaymentIntentModel();
        $intent->id            = 'pi-test-001';
        $intent->amount_minor  = 10000;
        $intent->currency_code = 'BWP';
        $intent->purpose_type  = 'wallet_transfer';

        $attempt = new PaymentAttemptModel();
        $attempt->provider_code  = 'fnb_botswana';
        $attempt->fee_minor      = 300;
        $attempt->confirmed_at   = now();

        $record = ReceiptRecord::fromModels($intent, $attempt, []);

        // Assert no sensitive fields exist on ReceiptRecord (FR-012)
        $this->assertFalse(property_exists($record, 'provider_credentials'));
        $this->assertFalse(property_exists($record, 'api_key'));
        $this->assertFalse(property_exists($record, 'account_number'));

        // providerLabel must be a display string, not a raw bank account number
        $this->assertNotNull($record->providerLabel);
        $this->assertDoesNotMatchRegularExpression('/^\d{8,}$/', $record->providerLabel ?? '');
    }

    public function test_receipt_record_from_models_sets_fee_from_attempt(): void
    {
        $intent = new PaymentIntentModel();
        $intent->id            = 'pi-test-002';
        $intent->amount_minor  = 5000;
        $intent->currency_code = 'BWP';
        $intent->purpose_type  = 'wallet_transfer';

        $attempt = new PaymentAttemptModel();
        $attempt->provider_code = 'test_bank';
        $attempt->fee_minor     = 150;
        $attempt->confirmed_at  = now();

        $record = ReceiptRecord::fromModels($intent, $attempt, []);

        $this->assertSame(150, $record->feeAmountMinor);
        $this->assertSame(5150, $record->totalChargedMinor);
        $this->assertSame(5000, $record->originalAmountMinor);
        $this->assertSame('confirmed', $record->receiptStatus);
    }

    public function test_receipt_record_total_charged_is_original_plus_fee(): void
    {
        $intent = new PaymentIntentModel();
        $intent->id            = 'pi-test-003';
        $intent->amount_minor  = 20000;
        $intent->currency_code = 'USD';
        $intent->purpose_type  = 'service_payment';

        $attempt = new PaymentAttemptModel();
        $attempt->provider_code = 'test_provider';
        $attempt->fee_minor     = 1000;
        $attempt->confirmed_at  = now();

        $record = ReceiptRecord::fromModels($intent, $attempt, []);

        $this->assertSame(20000, $record->originalAmountMinor);
        $this->assertSame(1000, $record->feeAmountMinor);
        $this->assertSame(21000, $record->totalChargedMinor);
    }
}
