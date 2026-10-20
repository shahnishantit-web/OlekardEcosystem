<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Contracts;

use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;

interface KycCaseRepositoryInterface
{
    /**
     * Returns the most recent open KYC case for the given user, or null if none exists.
     * Open = initiated, documents_submitted, under_review, needs_info, or rejected.
     */
    public function findOpenCaseForUser(string $userId): ?KycCaseModel;

    /**
     * Returns the case by ID only if it belongs to the given actor.
     * Returns null (not throws) when the case exists but belongs to a different actor — callers
     * MUST return HTTP 404 to avoid leaking another user's case existence.
     */
    public function findByIdForActor(string $caseId, string $actorId): ?KycCaseModel;

    /**
     * Returns the case by ID regardless of ownership. For admin/reviewer use only.
     */
    public function findById(string $caseId): ?KycCaseModel;
}
