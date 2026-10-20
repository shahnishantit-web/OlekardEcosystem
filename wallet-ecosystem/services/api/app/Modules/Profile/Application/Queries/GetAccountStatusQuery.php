<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Queries;

use App\Modules\Profile\Application\DTOs\AccountStatusDTO;
use App\Modules\Profile\Application\DTOs\AccountStatusHistoryEntryDTO;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Infrastructure\Models\AccountStatusHistoryModel;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;

final class GetAccountStatusQuery
{
    public function __construct(
        private readonly EloquentUserProfileRepository         $profiles,
        private readonly EloquentAccountStatusHistoryRepository $history,
    ) {}

    /**
     * @throws ProfileNotFoundException
     * @param bool $includeActorId false for user path; true for admin path (spec §6 US8)
     */
    public function execute(string $userId, bool $includeActorId = false): AccountStatusDTO
    {
        $profile = $this->profiles->findByUserId($userId);

        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$userId}");
        }

        $historyEntries = $this->history->getHistoryForUser($userId)
            ->map(static fn(AccountStatusHistoryModel $entry): AccountStatusHistoryEntryDTO => new AccountStatusHistoryEntryDTO(
                fromStatus: $entry->from_status,
                toStatus:   $entry->to_status,
                reason:     $entry->reason,
                actorType:  $entry->actor_type,
                actorId:    $includeActorId ? $entry->actor_id : null,
                createdAt:  $entry->created_at->toIso8601String(),
            ))
            ->all();

        return new AccountStatusDTO(
            accountStatus: $profile->account_status,
            history:       $historyEntries,
        );
    }
}
