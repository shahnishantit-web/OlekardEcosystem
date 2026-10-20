<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Handlers;

use App\Modules\Identity\Application\Commands\ListDevicesCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;

final class ListDevicesHandler
{
    public function __construct(
        private readonly DeviceRepositoryInterface $devices,
    ) {}

    public function handle(ListDevicesCommand $command): array
    {
        return $this->devices->listForUserPaginated(
            userId:   $command->userId,
            page:     $command->page,
            perPage:  $command->perPage,
            status:   $command->status,
            platform: $command->platform,
        );
    }
}
