<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\BankRepositoryInterface;
use App\Modules\Payment\Domain\Enums\BankLifecycleStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class BankAdminService
{
    public function __construct(
        private readonly BankRepositoryInterface $bankRepository,
        private readonly AuditServiceInterface   $audit,
    ) {
    }

    /**
     * @param array{status?: string, code?: string, display_name?: string} $filters
     * @return LengthAwarePaginator<PaymentProviderModel>
     */
    public function listBanks(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->bankRepository->listPaginated($filters, $perPage);
    }

    public function findBank(string $id): ?PaymentProviderModel
    {
        return $this->bankRepository->findById($id);
    }

    /**
     * @param array{code: string, display_name: string, adapter_identifier: string, adapter_version: string, environment: string, supported_countries?: array<string>, organisation_ownership?: string, description?: string, logo_reference?: string} $data
     */
    public function createBank(array $data, string $actorId, string $correlationId): PaymentProviderModel
    {
        $bank = new PaymentProviderModel();
        $bank->fill([
            'id'                    => (string) Str::uuid7(),
            'code'                  => $data['code'],
            'display_name'          => $data['display_name'],
            'adapter_identifier'    => $data['adapter_identifier'],
            'adapter_version'       => $data['adapter_version'],
            'environment'           => $data['environment'],
            'is_enabled'            => false,
            'is_suspended'          => false,
            'is_default'            => false,
            'sort_order'            => 0,
            'bank_lifecycle_status' => BankLifecycleStatus::Draft->value,
            'supported_countries'   => $data['supported_countries'] ?? [],
            'organisation_ownership' => $data['organisation_ownership'] ?? null,
            'description'           => $data['description'] ?? null,
            'logo_reference'        => $data['logo_reference'] ?? null,
            'metadata_version'      => 1,
        ]);

        $saved = $this->bankRepository->save($bank);

        $this->audit->record(new AuditEventData(
            action:        'payment.bank.created',
            actorType:     'admin',
            resourceType:  'payment_provider',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $saved->id,
            afterState:    ['code' => $saved->code, 'status' => $saved->bank_lifecycle_status],
        ));

        return $saved;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateBank(string $id, array $data, int $expectedMetadataVersion, string $actorId): PaymentProviderModel
    {
        $bank = $this->bankRepository->findById($id);

        if ($bank === null) {
            throw new \RuntimeException("Bank not found: {$id}");
        }

        if ($bank->metadata_version !== $expectedMetadataVersion) {
            throw new \RuntimeException("Optimistic lock conflict: expected version {$expectedMetadataVersion}, got {$bank->metadata_version}");
        }

        $before = ['code' => $bank->code, 'status' => $bank->bank_lifecycle_status, 'version' => $bank->metadata_version];

        $allowed = ['display_name', 'description', 'logo_reference', 'supported_countries', 'organisation_ownership'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $bank->$field = $data[$field];
            }
        }

        $bank->metadata_version = $expectedMetadataVersion + 1;

        $saved = $this->bankRepository->save($bank);

        $this->audit->record(new AuditEventData(
            action:        'payment.bank.updated',
            actorType:     'admin',
            resourceType:  'payment_provider',
            correlationId: $id,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $saved->id,
            beforeState:   $before,
            afterState:    ['code' => $saved->code, 'version' => $saved->metadata_version],
        ));

        return $saved;
    }
}
