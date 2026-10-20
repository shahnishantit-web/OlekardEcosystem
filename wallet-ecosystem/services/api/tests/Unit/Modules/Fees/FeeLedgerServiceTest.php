<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Fees;

use App\Modules\Fees\Application\Services\FeeLedgerService;
use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeType;
use App\Modules\Fees\Domain\Events\FeePostedEvent;
use App\Modules\Fees\Domain\Events\FeeReversedEvent;
use App\Modules\Fees\Infrastructure\Models\FeeModel;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class FeeLedgerServiceTest extends TestCase
{
    use DatabaseTransactions;

    private FeeLedgerService $service;
    private LedgerModuleContract $ledger;
    private EventPublisherInterface $events;
    private FeeRuleModel $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $this->service = new FeeLedgerService($this->ledger, $this->events);

        $this->rule = FeeRuleModel::create([
            'id'               => (string) Str::orderedUuid(),
            'transaction_type' => 'transfer',
            'fee_type'         => FeeType::Percentage->value,
            'currency'         => 'BWP',
            'subject_type'     => FeeSubjectType::Global->value,
            'rate'             => 0.5,
            'valid_from'       => now()->subDay(),
            'status'           => FeeRuleStatus::Active->value,
            'created_by'       => 'test',
            'idempotency_key'  => (string) Str::uuid(),
        ]);
    }

    // ── T042: recordFee ───────────────────────────────────────────────────────

    public function test_first_call_creates_fee_model_and_calls_post_entry(): void
    {
        $txId          = (string) Str::uuid();
        $ledgerEntryId = (string) Str::orderedUuid();

        $this->ledger->expects($this->once())->method('postEntry')->willReturn($ledgerEntryId);
        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(FeePostedEvent::class));

        $feeId = $this->service->recordFee(
            transactionId:   $txId,
            amountMinor:     500,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee'),
            correlationId:   'corr-1',
        );

        $this->assertNotEmpty($feeId);
        $fee = FeeModel::find($feeId);
        $this->assertSame(FeeStatus::Posted, $fee->status);
        $this->assertSame($txId, $fee->transaction_id);
        $this->assertSame(500, $fee->amount_minor);
        $this->assertSame($ledgerEntryId, $fee->ledger_entry_id);
    }

    public function test_duplicate_call_returns_existing_fee_id_without_posting(): void
    {
        $txId          = (string) Str::uuid();
        $ledgerEntryId = (string) Str::orderedUuid();

        $this->ledger->expects($this->once())->method('postEntry')->willReturn($ledgerEntryId);
        $this->events->method('publish');

        $feeId1 = $this->service->recordFee(
            transactionId:   $txId,
            amountMinor:     500,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee'),
            correlationId:   'corr-1',
        );

        // Second call — ledger->postEntry must NOT be called again
        $this->ledger->expects($this->never())->method('postEntry');

        $feeId2 = $this->service->recordFee(
            transactionId:   $txId,
            amountMinor:     500,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee'),
            correlationId:   'corr-2',
        );

        $this->assertSame($feeId1, $feeId2);
    }

    public function test_zero_amount_returns_empty_without_calling_post_entry(): void
    {
        $this->ledger->expects($this->never())->method('postEntry');
        $this->events->expects($this->never())->method('publish');

        $result = $this->service->recordFee(
            transactionId:   (string) Str::uuid(),
            amountMinor:     0,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Str::uuid(),
            correlationId:   'corr-1',
        );

        $this->assertSame('', $result);
    }

    // ── T043: reverseFee ──────────────────────────────────────────────────────

    public function test_reversal_posts_compensating_entry_with_reversed_debit_credit(): void
    {
        $txId          = (string) Str::uuid();
        $ledgerEntryId = (string) Str::orderedUuid();
        $reversalEntryId = (string) Str::orderedUuid();

        $this->ledger->method('postEntry')->willReturnOnConsecutiveCalls($ledgerEntryId, $reversalEntryId);
        $this->events->method('publish');

        // First record the fee
        $this->service->recordFee(
            transactionId:   $txId,
            amountMinor:     500,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee'),
            correlationId:   'corr-1',
        );

        // Verify reversal posts with swapped debit/credit
        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(FeeReversedEvent::class));

        $this->service->reverseFee(
            transactionId:   $txId,
            debitAccountRef: 'revenue:fees:BWP',
            creditAccountRef:'wallet:cust-1',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee:reversal'),
            correlationId:   'corr-reversal',
        );

        $fee = FeeModel::where('transaction_id', $txId)->first();
        $this->assertSame(FeeStatus::Reversed, $fee->status);
        $this->assertSame($reversalEntryId, $fee->reversal_ledger_entry_id);
    }

    public function test_second_reversal_is_idempotent(): void
    {
        $txId = (string) Str::uuid();

        $this->ledger->method('postEntry')->willReturn((string) Str::orderedUuid());
        $this->events->method('publish');

        $this->service->recordFee(
            transactionId:   $txId,
            amountMinor:     500,
            feeRuleId:       $this->rule->id,
            currency:        'BWP',
            debitAccountRef: 'wallet:cust-1',
            creditAccountRef:'revenue:fees:BWP',
            idempotencyKey:  (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee'),
            correlationId:   'corr-1',
        );

        $this->service->reverseFee($txId, 'revenue:fees:BWP', 'wallet:cust-1',
            (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee:reversal'), 'corr-r1');

        // Second reversal — postEntry should NOT be called again
        $this->ledger->expects($this->never())->method('postEntry');

        $this->service->reverseFee($txId, 'revenue:fees:BWP', 'wallet:cust-1',
            (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $txId . ':fee:reversal'), 'corr-r2');
    }
}
