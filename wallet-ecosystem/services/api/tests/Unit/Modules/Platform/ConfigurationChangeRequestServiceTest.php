<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform;

use App\Exceptions\BusinessRuleViolationException;
use App\Exceptions\DuplicateResourceException;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\RejectChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\WithdrawChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationApprovalRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationVersionRepositoryInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationChangeRequestModel;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\Support\PlatformTestCase;
use Illuminate\Support\Facades\DB;

/**
 * T067 — Unit tests for ConfigurationChangeRequestService.
 *
 * Tests all happy paths and rejection paths using mocked repository dependencies.
 * Uses PlatformTestCase (DB available) because ConfigurationChangeRequestService
 * has a direct DB::connection call in getCurrentValue().
 */
final class ConfigurationChangeRequestServiceTest extends PlatformTestCase
{
    private ConfigurationChangeRequestRepositoryInterface $changeRequestRepo;
    private ConfigurationVersionRepositoryInterface $versionRepo;
    private ConfigurationApprovalRepositoryInterface $approvalRepo;
    private AuditServiceInterface $auditService;
    private NotificationModuleContract $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changeRequestRepo = Mockery::mock(ConfigurationChangeRequestRepositoryInterface::class);
        $this->versionRepo       = Mockery::mock(ConfigurationVersionRepositoryInterface::class);
        $this->approvalRepo      = Mockery::mock(ConfigurationApprovalRepositoryInterface::class);
        $this->auditService      = Mockery::mock(AuditServiceInterface::class);
        $this->notifications     = Mockery::mock(NotificationModuleContract::class);

        // Allow any audit calls — not under test here
        $this->auditService->shouldReceive('record')->withAnyArgs()->andReturnNull()->byDefault();
    }

    private function makeService(array $validators = []): ConfigurationChangeRequestService
    {
        return new ConfigurationChangeRequestService(
            changeRequestRepository: $this->changeRequestRepo,
            versionRepository:       $this->versionRepo,
            approvalRepository:      $this->approvalRepo,
            auditService:            $this->auditService,
            notifications:           $this->notifications,
            validators:              $validators,
        );
    }

    private function seedConfigKey(string $key, string $domain = 'operational_policy'): void
    {
        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => $key,
            'domain'      => $domain,
            'description' => "Test key {$key}",
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ─── submit() ────────────────────────────────────────────────────────────

    public function test_submit_creates_change_request_and_version_record(): void
    {
        $key = 'operational_policy.unit_test_submit';
        $this->seedConfigKey($key);

        $makerId        = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        // No existing record for this idempotency key
        $this->changeRequestRepo->shouldReceive('findByIdempotencyKey')
            ->with($idempotencyKey)
            ->once()
            ->andReturn(null);

        // No pending request for this key
        $this->changeRequestRepo->shouldReceive('findPendingByKey')
            ->with($key)
            ->once()
            ->andReturn(null);

        // Save should be called once
        $this->changeRequestRepo->shouldReceive('save')
            ->once()
            ->withArgs(function (ConfigurationChangeRequestModel $model) use ($key, $makerId) {
                return $model->config_key === $key
                    && $model->maker_id === $makerId
                    && $model->status === ChangeRequestStatus::PendingApproval;
            });

        // Version record insert should be called once
        $this->versionRepo->shouldReceive('insert')
            ->once()
            ->withArgs(function (array $attrs) {
                return $attrs['event_type'] === VersionEventType::Submitted->value
                    && $attrs['maker_id'] !== null;
            });

        $result = $this->makeService()->submit(new SubmitChangeRequestDTO(
            configKey:      $key,
            proposedValue:  ['value' => 999],
            makerId:        $makerId,
            idempotencyKey: $idempotencyKey,
            correlationId:  (string) Uuid::uuid7(),
        ));

        $this->assertEquals($key, $result['config_key']);
        $this->assertEquals('pending_approval', $result['status']);
        $this->assertEquals($makerId, $result['maker_id']);
    }

    public function test_submit_is_idempotent_for_same_idempotency_key(): void
    {
        $idempotencyKey = (string) Uuid::uuid7();

        $existingModel                 = new ConfigurationChangeRequestModel();
        $existingModel->id             = (string) Uuid::uuid7();
        $existingModel->config_key     = 'operational_policy.unit_test_idempotent';
        $existingModel->domain         = ConfigurationDomain::OperationalPolicy;
        $existingModel->proposed_value = ['value' => 100];
        $existingModel->previous_value = null;
        $existingModel->maker_id       = (string) Uuid::uuid7();
        $existingModel->status         = ChangeRequestStatus::PendingApproval;
        $existingModel->submitted_at   = now();

        $this->changeRequestRepo->shouldReceive('findByIdempotencyKey')
            ->with($idempotencyKey)
            ->once()
            ->andReturn($existingModel);

        // save() and insert() must NOT be called for idempotent submit
        $this->changeRequestRepo->shouldNotReceive('save');
        $this->versionRepo->shouldNotReceive('insert');

        $result = $this->makeService()->submit(new SubmitChangeRequestDTO(
            configKey:      'operational_policy.unit_test_idempotent',
            proposedValue:  ['value' => 100],
            makerId:        $existingModel->maker_id,
            idempotencyKey: $idempotencyKey,
            correlationId:  (string) Uuid::uuid7(),
        ));

        $this->assertEquals($existingModel->id, $result['id']);
    }

    public function test_submit_throws_when_pending_request_exists_for_same_key(): void
    {
        $key = 'operational_policy.unit_test_duplicate';
        $this->seedConfigKey($key);

        $idempotencyKey = (string) Uuid::uuid7();

        $this->changeRequestRepo->shouldReceive('findByIdempotencyKey')
            ->andReturn(null);

        $pendingModel         = new ConfigurationChangeRequestModel();
        $pendingModel->id     = (string) Uuid::uuid7();
        $pendingModel->status = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('findPendingByKey')
            ->with($key)
            ->andReturn($pendingModel);

        $this->expectException(DuplicateResourceException::class);

        $this->makeService()->submit(new SubmitChangeRequestDTO(
            configKey:      $key,
            proposedValue:  ['value' => 200],
            makerId:        (string) Uuid::uuid7(),
            idempotencyKey: $idempotencyKey,
            correlationId:  (string) Uuid::uuid7(),
        ));
    }

    // ─── approve() ───────────────────────────────────────────────────────────

    public function test_approve_happy_path_writes_approval_and_draft_config_value(): void
    {
        $changeRequestId = (string) Uuid::uuid7();
        $makerId         = (string) Uuid::uuid7();
        $checkerId       = (string) Uuid::uuid7();
        $idempotencyKey  = (string) Uuid::uuid7();

        $this->approvalRepo->shouldReceive('findByIdempotencyKey')
            ->with($idempotencyKey)
            ->once()
            ->andReturn(null);

        $changeRequest                  = new ConfigurationChangeRequestModel();
        $changeRequest->id              = $changeRequestId;
        $changeRequest->config_key      = 'financial_policy.unit_approve_test';
        $changeRequest->domain          = ConfigurationDomain::FinancialPolicy;
        $changeRequest->proposed_value  = ['value' => 5000];
        $changeRequest->previous_value  = ['value' => 3000];
        $changeRequest->maker_id        = $makerId;
        $changeRequest->status          = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('lockForUpdate')
            ->with($changeRequestId)
            ->once()
            ->andReturn($changeRequest);

        $this->approvalRepo->shouldReceive('save')->once();
        $this->versionRepo->shouldReceive('insert')
            ->once()
            ->withArgs(function (array $attrs) use ($makerId, $checkerId) {
                return $attrs['event_type'] === VersionEventType::Approved->value
                    && $attrs['maker_id'] === $makerId
                    && $attrs['checker_id'] === $checkerId;
            });

        $changeRequest->status      = ChangeRequestStatus::Approved;
        $changeRequest->resolved_at = now();
        $this->changeRequestRepo->shouldReceive('save')->once()->andReturnNull();

        DB::connection('platform')->table('platform.configurations')->insertOrIgnore([
            'config_key'  => 'financial_policy.unit_approve_test',
            'domain'      => 'financial_policy',
            'description' => 'Unit test approve key',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $result = $this->makeService()->approve(new ApproveChangeRequestDTO(
            changeRequestId: $changeRequestId,
            checkerId:       $checkerId,
            idempotencyKey:  $idempotencyKey,
            correlationId:   (string) Uuid::uuid7(),
        ));

        $this->assertEquals('approved', $result['status']);
    }

    public function test_approve_throws_business_rule_violation_when_self_approval(): void
    {
        $userId         = (string) Uuid::uuid7();
        $idempotencyKey = (string) Uuid::uuid7();

        $this->approvalRepo->shouldReceive('findByIdempotencyKey')->andReturn(null);

        $changeRequest             = new ConfigurationChangeRequestModel();
        $changeRequest->id         = (string) Uuid::uuid7();
        $changeRequest->config_key = 'operational_policy.self_approve_test';
        $changeRequest->domain     = ConfigurationDomain::OperationalPolicy;
        $changeRequest->maker_id   = $userId;
        $changeRequest->status     = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('lockForUpdate')
            ->andReturn($changeRequest);

        $this->expectException(BusinessRuleViolationException::class);

        $this->makeService()->approve(new ApproveChangeRequestDTO(
            changeRequestId: $changeRequest->id,
            checkerId:       $userId, // same as maker — FR-006
            idempotencyKey:  $idempotencyKey,
            correlationId:   (string) Uuid::uuid7(),
        ));
    }

    public function test_approve_throws_when_change_request_not_in_pending_approval(): void
    {
        $idempotencyKey = (string) Uuid::uuid7();

        $this->approvalRepo->shouldReceive('findByIdempotencyKey')->andReturn(null);

        $changeRequest         = new ConfigurationChangeRequestModel();
        $changeRequest->id     = (string) Uuid::uuid7();
        $changeRequest->status = ChangeRequestStatus::Approved; // already approved

        $this->changeRequestRepo->shouldReceive('lockForUpdate')
            ->andReturn($changeRequest);

        $this->expectException(BusinessRuleViolationException::class);

        $this->makeService()->approve(new ApproveChangeRequestDTO(
            changeRequestId: $changeRequest->id,
            checkerId:       (string) Uuid::uuid7(),
            idempotencyKey:  $idempotencyKey,
            correlationId:   (string) Uuid::uuid7(),
        ));
    }

    // ─── reject() ────────────────────────────────────────────────────────────

    public function test_reject_happy_path_writes_rejected_version_record(): void
    {
        $changeRequestId = (string) Uuid::uuid7();
        $makerId         = (string) Uuid::uuid7();
        $checkerId       = (string) Uuid::uuid7();
        $idempotencyKey  = (string) Uuid::uuid7();

        $this->approvalRepo->shouldReceive('findByIdempotencyKey')->andReturn(null);

        $changeRequest               = new ConfigurationChangeRequestModel();
        $changeRequest->id           = $changeRequestId;
        $changeRequest->config_key   = 'operational_policy.reject_test';
        $changeRequest->domain       = ConfigurationDomain::OperationalPolicy;
        $changeRequest->proposed_value = ['value' => 55];
        $changeRequest->previous_value = null;
        $changeRequest->maker_id     = $makerId;
        $changeRequest->status       = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('lockForUpdate')
            ->andReturn($changeRequest);

        $this->approvalRepo->shouldReceive('save')->once();

        $this->versionRepo->shouldReceive('insert')
            ->once()
            ->withArgs(function (array $attrs) {
                return $attrs['event_type'] === VersionEventType::Rejected->value
                    && $attrs['notes'] === 'Compliance reason';
            });

        $changeRequest->status           = ChangeRequestStatus::Rejected;
        $changeRequest->rejection_reason = 'Compliance reason';
        $changeRequest->resolved_at      = now();
        $this->changeRequestRepo->shouldReceive('save')->once();

        $this->notifications->shouldReceive('dispatch')->andReturnNull()->byDefault();

        $result = $this->makeService()->reject(new RejectChangeRequestDTO(
            changeRequestId: $changeRequestId,
            checkerId:       $checkerId,
            reason:          'Compliance reason',
            idempotencyKey:  $idempotencyKey,
            correlationId:   (string) Uuid::uuid7(),
        ));

        $this->assertEquals('rejected', $result['status']);
    }

    // ─── withdraw() ──────────────────────────────────────────────────────────

    public function test_withdraw_by_non_maker_throws_business_rule_violation(): void
    {
        $changeRequestId = (string) Uuid::uuid7();
        $makerId         = (string) Uuid::uuid7();
        $nonMakerId      = (string) Uuid::uuid7();

        $this->changeRequestRepo->shouldReceive('findByIdempotencyKey')->andReturn(null);

        $changeRequest           = new ConfigurationChangeRequestModel();
        $changeRequest->id       = $changeRequestId;
        $changeRequest->maker_id = $makerId;
        $changeRequest->status   = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('findById')
            ->with($changeRequestId)
            ->andReturn($changeRequest);

        $this->expectException(BusinessRuleViolationException::class);

        $this->makeService()->withdraw(new WithdrawChangeRequestDTO(
            changeRequestId: $changeRequestId,
            makerId:         $nonMakerId, // not the original maker
            idempotencyKey:  (string) Uuid::uuid7(),
            correlationId:   (string) Uuid::uuid7(),
        ));
    }

    public function test_withdraw_by_maker_succeeds_and_writes_withdrawn_version_record(): void
    {
        $changeRequestId = (string) Uuid::uuid7();
        $makerId         = (string) Uuid::uuid7();
        $idempotencyKey  = (string) Uuid::uuid7();

        $this->changeRequestRepo->shouldReceive('findByIdempotencyKey')->andReturn(null);

        $changeRequest               = new ConfigurationChangeRequestModel();
        $changeRequest->id           = $changeRequestId;
        $changeRequest->config_key   = 'operational_policy.withdraw_test';
        $changeRequest->domain       = ConfigurationDomain::OperationalPolicy;
        $changeRequest->proposed_value = ['value' => 10];
        $changeRequest->previous_value = null;
        $changeRequest->maker_id     = $makerId;
        $changeRequest->status       = ChangeRequestStatus::PendingApproval;

        $this->changeRequestRepo->shouldReceive('findById')
            ->with($changeRequestId)
            ->andReturn($changeRequest);

        $this->versionRepo->shouldReceive('insert')
            ->once()
            ->withArgs(function (array $attrs) {
                return $attrs['event_type'] === VersionEventType::Withdrawn->value;
            });

        $changeRequest->status      = ChangeRequestStatus::Withdrawn;
        $changeRequest->resolved_at = now();
        $this->changeRequestRepo->shouldReceive('save')->once();

        $result = $this->makeService()->withdraw(new WithdrawChangeRequestDTO(
            changeRequestId: $changeRequestId,
            makerId:         $makerId,
            idempotencyKey:  $idempotencyKey,
            correlationId:   (string) Uuid::uuid7(),
        ));

        $this->assertEquals('withdrawn', $result['status']);
    }
}
