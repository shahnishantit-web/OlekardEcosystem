<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Resources;

use App\Modules\Profile\Application\DTOs\AccountStatusDTO;
use App\Modules\Profile\Application\DTOs\AccountStatusHistoryEntryDTO;
use Illuminate\Http\Resources\Json\JsonResource;

// actor_id is omitted (not set to null) on the user path per spec §6 US8 and contracts/profile-api.md.
final class AccountStatusResource extends JsonResource
{
    public function __construct(private readonly AccountStatusDTO $status) {}

    public function toArray($request): array
    {
        return [
            'success' => true,
            'data'    => [
                'account_status' => $this->status->accountStatus,
                'history'        => array_map(
                    static fn(AccountStatusHistoryEntryDTO $entry): array => array_merge(
                        [
                            'from_status' => $entry->fromStatus,
                            'to_status'   => $entry->toStatus,
                            'reason'      => $entry->reason,
                            'actor_type'  => $entry->actorType,
                            'created_at'  => $entry->createdAt,
                        ],
                        $entry->actorId !== null ? ['actor_id' => $entry->actorId] : [],
                    ),
                    $this->status->history,
                ),
            ],
        ];
    }
}
