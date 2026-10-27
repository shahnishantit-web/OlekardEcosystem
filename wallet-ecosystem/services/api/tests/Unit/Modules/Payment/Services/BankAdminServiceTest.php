<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Application\Services\BankAdminService;
use App\Modules\Payment\Domain\Contracts\BankRepositoryInterface;
use App\Modules\Payment\Domain\Enums\BankLifecycleStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BankAdminServiceTest extends TestCase
{
    private BankRepositoryInterface&MockObject $bankRepository;
    private AuditServiceInterface&MockObject   $audit;
    private BankAdminService                   $service;

    protected function setUp(): void
    {
        $this->bankRepository = $this->createMock(BankRepositoryInterface::class);
        $this->audit          = $this->createMock(AuditServiceInterface::class);
        $this->service        = new BankAdminService($this->bankRepository, $this->audit);
    }

    public function test_create_bank_sets_draft_status_and_saves(): void
    {
        $captured = null;

        $this->bankRepository
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (PaymentProviderModel $bank) use (&$captured): PaymentProviderModel {
                $captured = $bank;
                return $bank;
            });

        $this->audit->expects($this->once())->method('record');

        $bank = $this->service->createBank(
            [
                'code'               => 'FNBZ',
                'display_name'       => 'First National Bank',
                'adapter_identifier' => 'http-redirect-v1',
                'adapter_version'    => '1.0.0',
                'environment'        => 'sandbox',
                'supported_countries' => ['BW'],
            ],
            actorId:       'actor-001',
            correlationId: 'corr-001',
        );

        $this->assertSame(BankLifecycleStatus::Draft->value, $bank->bank_lifecycle_status);
        $this->assertSame('FNBZ', $bank->code);
        $this->assertSame(['BW'], $bank->supported_countries);
        $this->assertSame(1, $bank->metadata_version);
    }

    public function test_create_bank_audits_creation(): void
    {
        $this->bankRepository->method('save')->willReturnArgument(0);

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (AuditEventData $event): bool {
                return $event->action === 'payment.bank.created'
                    && $event->outcome === 'success';
            }));

        $this->service->createBank(
            ['code' => 'STANBZ', 'display_name' => 'Standard Bank', 'adapter_identifier' => 'http-redirect-v1', 'adapter_version' => '1.0.0', 'environment' => 'sandbox'],
            'actor-001',
            'corr-002'
        );
    }

    public function test_update_bank_throws_on_optimistic_lock_conflict(): void
    {
        $bank = new PaymentProviderModel();
        $bank->forceFill(['id' => 'bank-001', 'code' => 'FNBZ', 'metadata_version' => 3, 'bank_lifecycle_status' => BankLifecycleStatus::Draft->value]);

        $this->bankRepository->method('findById')->willReturn($bank);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Optimistic lock conflict/');

        $this->service->updateBank('bank-001', ['display_name' => 'New Name'], expectedMetadataVersion: 2, actorId: 'actor-001');
    }

    public function test_update_bank_increments_metadata_version(): void
    {
        $bank = new PaymentProviderModel();
        $bank->forceFill(['id' => 'bank-001', 'code' => 'FNBZ', 'metadata_version' => 1, 'bank_lifecycle_status' => BankLifecycleStatus::Draft->value, 'display_name' => 'Old Name']);

        $this->bankRepository->method('findById')->willReturn($bank);
        $this->bankRepository->method('save')->willReturnArgument(0);
        $this->audit->method('record');

        $updated = $this->service->updateBank('bank-001', ['display_name' => 'New Name'], expectedMetadataVersion: 1, actorId: 'actor-001');

        $this->assertSame(2, $updated->metadata_version);
        $this->assertSame('New Name', $updated->display_name);
    }

    public function test_update_bank_audits_update(): void
    {
        $bank = new PaymentProviderModel();
        $bank->forceFill(['id' => 'bank-001', 'code' => 'FNBZ', 'metadata_version' => 1, 'bank_lifecycle_status' => BankLifecycleStatus::Draft->value]);

        $this->bankRepository->method('findById')->willReturn($bank);
        $this->bankRepository->method('save')->willReturnArgument(0);

        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with($this->callback(static function (AuditEventData $event): bool {
                return $event->action === 'payment.bank.updated';
            }));

        $this->service->updateBank('bank-001', ['display_name' => 'Updated'], 1, 'actor-001');
    }

    public function test_create_bank_raw_credentials_never_returned(): void
    {
        $this->bankRepository->method('save')->willReturnArgument(0);
        $this->audit->method('record');

        $bank = $this->service->createBank(
            ['code' => 'FNBZ', 'display_name' => 'FNB', 'adapter_identifier' => 'http-redirect-v1', 'adapter_version' => '1.0.0', 'environment' => 'sandbox'],
            'actor-001',
            'corr-001',
        );

        $serialised = json_encode($bank->toArray());

        $this->assertStringNotContainsString('Bearer ', (string) $serialised);
        $this->assertStringNotContainsString('sk_', (string) $serialised);
    }
}
