<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class DataExportReadyEvent extends BaseIntegrationEvent
{
    public function __construct(
        string                  $userId,
        string                  $correlationId,
        private readonly string $exportId,
        private readonly string $downloadUrl,
    ) {
        parent::__construct($userId, null, $correlationId);
    }

    public function aggregateType(): string
    {
        return 'Profile.User';
    }

    public function eventName(): string
    {
        return 'Profile.User.DataExportReady';
    }

    public function payload(): array
    {
        return [
            'user_id'      => $this->aggregateId(),
            'export_id'    => $this->exportId,
            'download_url' => $this->downloadUrl,
        ];
    }
}
