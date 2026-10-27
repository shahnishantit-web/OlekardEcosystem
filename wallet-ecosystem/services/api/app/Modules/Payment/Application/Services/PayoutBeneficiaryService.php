<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PayoutBeneficiaryRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Domain\Exceptions\BeneficiaryNotVerifiedException;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Ramsey\Uuid\Uuid;

final class PayoutBeneficiaryService
{
    public function __construct(
        private readonly PayoutBeneficiaryRepositoryInterface $repository,
        private readonly AuditServiceInterface                $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function register(array $data, string $actorId): PayoutBeneficiaryModel
    {
        $model = new PayoutBeneficiaryModel([
            'id'                   => (string) Uuid::uuid7(),
            'organisation_id'      => $data['organisation_id'],
            'account_type'         => $data['account_type'],
            'account_number'       => $data['account_number'],
            'bank_code'            => $data['bank_code'] ?? null,
            'bank_name'            => $data['bank_name'] ?? null,
            'account_holder_name'  => $data['account_holder_name'],
            'currency_code'        => $data['currency_code'],
            'status'               => PayoutBeneficiaryStatus::PendingVerification,
            'created_by'           => $actorId,
        ]);

        $this->repository->save($model);

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.beneficiary_registered',
            actorType:    'admin',
            resourceType: 'payout_beneficiary',
            correlationId: $model->id,
            outcome:      'success',
            actorId:      $actorId,
            resourceId:   $model->id,
            afterState:   ['status' => PayoutBeneficiaryStatus::PendingVerification->value],
        ));

        return $model;
    }

    public function recordVerification(
        string $beneficiaryId,
        string $verificationReference,
        string $actorId,
    ): PayoutBeneficiaryModel {
        $model = $this->repository->findById($beneficiaryId);

        if ($model === null) {
            throw new \RuntimeException("Beneficiary {$beneficiaryId} not found.");
        }

        $model->status                  = PayoutBeneficiaryStatus::Verified;
        $model->verified_at             = now();
        $model->verification_reference  = $verificationReference;

        $this->repository->save($model);

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.beneficiary_verified',
            actorType:    'admin',
            resourceType: 'payout_beneficiary',
            correlationId: $beneficiaryId,
            outcome:      'success',
            actorId:      $actorId,
            resourceId:   $beneficiaryId,
            afterState:   ['status' => PayoutBeneficiaryStatus::Verified->value],
        ));

        return $model;
    }

    public function assertVerified(string $beneficiaryId): void
    {
        $model = $this->repository->findById($beneficiaryId);

        if ($model === null || $model->status !== PayoutBeneficiaryStatus::Verified) {
            throw BeneficiaryNotVerifiedException::forBeneficiary($beneficiaryId);
        }
    }
}
