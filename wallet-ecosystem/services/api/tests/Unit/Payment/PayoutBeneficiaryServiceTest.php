<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Application\Services\PayoutBeneficiaryService;
use App\Modules\Payment\Domain\Contracts\PayoutBeneficiaryRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Domain\Exceptions\BeneficiaryNotVerifiedException;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T030: PayoutBeneficiaryService unit tests.
 *
 * DB-gated integration tests (e.g., encryption at rest) require Docker.
 */
final class PayoutBeneficiaryServiceTest extends TestCase
{
    private PayoutBeneficiaryRepositoryInterface&MockObject $repository;
    private AuditServiceInterface&MockObject                $audit;
    private PayoutBeneficiaryService                        $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(PayoutBeneficiaryRepositoryInterface::class);
        $this->audit      = $this->createMock(AuditServiceInterface::class);
        $this->service    = new PayoutBeneficiaryService($this->repository, $this->audit);
    }

    public function test_register_saves_beneficiary_with_pending_verification_status(): void
    {
        $this->repository->expects($this->once())->method('save');
        $this->audit->expects($this->once())->method('record');

        $model = $this->service->register([
            'organisation_id'     => 'org-001',
            'account_type'        => 'bank',
            'account_number'      => '1234567890',
            'account_holder_name' => 'Test Beneficiary',
            'currency_code'       => 'BWP',
        ], actor: 'admin-001');

        $this->assertSame(PayoutBeneficiaryStatus::PendingVerification, $model->status);
        $this->assertSame('org-001', $model->organisation_id);
        $this->assertSame('admin-001', $model->created_by);
    }

    public function test_register_generates_uuid_for_id(): void
    {
        $this->repository->method('save');
        $this->audit->method('record');

        $model = $this->service->register([
            'organisation_id'     => 'org-001',
            'account_type'        => 'bank',
            'account_number'      => '0987654321',
            'account_holder_name' => 'Another Beneficiary',
            'currency_code'       => 'ZAR',
        ], actor: 'admin-002');

        $this->assertNotEmpty($model->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $model->id,
        );
    }

    public function test_record_verification_transitions_status_to_verified(): void
    {
        $beneficiaryId = 'ben-001';
        $stub          = new PayoutBeneficiaryModel();
        $stub->status  = PayoutBeneficiaryStatus::PendingVerification;

        $this->repository->method('findById')->with($beneficiaryId)->willReturn($stub);
        $this->repository->expects($this->once())->method('save');
        $this->audit->expects($this->once())->method('record');

        $result = $this->service->recordVerification($beneficiaryId, 'ref-xyz', 'admin-001');

        $this->assertSame(PayoutBeneficiaryStatus::Verified, $result->status);
        $this->assertSame('ref-xyz', $result->verification_reference);
    }

    public function test_assert_verified_throws_when_beneficiary_not_found(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(BeneficiaryNotVerifiedException::class);

        $this->service->assertVerified('nonexistent-id');
    }

    public function test_assert_verified_throws_when_status_is_not_verified(): void
    {
        $stub         = new PayoutBeneficiaryModel();
        $stub->status = PayoutBeneficiaryStatus::PendingVerification;

        $this->repository->method('findById')->willReturn($stub);

        $this->expectException(BeneficiaryNotVerifiedException::class);

        $this->service->assertVerified('ben-pending');
    }
}
