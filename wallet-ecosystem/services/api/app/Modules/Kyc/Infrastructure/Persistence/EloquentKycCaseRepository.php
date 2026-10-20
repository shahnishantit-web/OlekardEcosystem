<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Persistence;

use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;

final class EloquentKycCaseRepository implements KycCaseRepositoryInterface
{
    private const OPEN_STATUSES = [
        KycCaseStatus::Initiated->value,
        KycCaseStatus::DocumentsSubmitted->value,
        KycCaseStatus::UnderReview->value,
        KycCaseStatus::NeedsInfo->value,
        KycCaseStatus::Rejected->value,
    ];

    public function findOpenCaseForUser(string $userId): ?KycCaseModel
    {
        return KycCaseModel::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->latest('created_at')
            ->first();
    }

    public function findByIdForActor(string $caseId, string $actorId): ?KycCaseModel
    {
        return KycCaseModel::query()
            ->where('id', $caseId)
            ->where('user_id', $actorId)
            ->first();
    }

    public function findById(string $caseId): ?KycCaseModel
    {
        return KycCaseModel::query()->find($caseId);
    }
}
