<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Jobs;

use App\Modules\Payment\Application\Jobs\RepairPendingAccountingJob;
use App\Modules\Payment\Application\Jobs\RepairSingleAccountingJob;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

/**
 * P08 US2: RepairPendingAccountingJob scan unit tests.
 * These tests do not require a database — all dependencies are mocked.
 */
final class RepairPendingAccountingJobTest extends TestCase
{
    /** @var MockObject&PaymentIntentRepositoryInterface */
    private MockObject $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->createMock(PaymentIntentRepositoryInterface::class);
    }

    public function test_dispatches_repair_job_for_each_pending_intent(): void
    {
        $intentA = new PaymentIntentModel(['id' => (string) Uuid::uuid7()]);
        $intentB = new PaymentIntentModel(['id' => (string) Uuid::uuid7()]);

        $this->repo->method('findPendingAccounting')
            ->willReturn(collect([$intentA, $intentB]));

        Bus::fake();
        (new RepairPendingAccountingJob())->handle($this->repo);
        Bus::assertDispatched(RepairSingleAccountingJob::class, static fn (RepairSingleAccountingJob $job): bool => $job->paymentIntentId === $intentA->id);
        Bus::assertDispatched(RepairSingleAccountingJob::class, static fn (RepairSingleAccountingJob $job): bool => $job->paymentIntentId === $intentB->id);
    }

    public function test_no_jobs_dispatched_when_no_pending_intents(): void
    {
        $this->repo->method('findPendingAccounting')
            ->willReturn(collect([]));

        Bus::fake();
        (new RepairPendingAccountingJob())->handle($this->repo);
        Bus::assertNothingDispatched();
    }

    public function test_job_has_unique_id_preventing_overlap(): void
    {
        $job = new RepairPendingAccountingJob();
        self::assertSame('payment_repair_accounting_scan', $job->uniqueId());
    }

    public function test_find_pending_accounting_called_with_limit_500(): void
    {
        $this->repo->expects(self::once())
            ->method('findPendingAccounting')
            ->with(self::identicalTo(500))
            ->willReturn(collect([]));

        (new RepairPendingAccountingJob())->handle($this->repo);
    }

    public function test_repair_single_job_unique_id_uses_intent_id(): void
    {
        $intentId = (string) Uuid::uuid7();
        $job      = new RepairSingleAccountingJob($intentId);

        self::assertSame('payment_repair_accounting:'.$intentId, $job->uniqueId());
        self::assertSame(1, $job->tries);
    }
}
