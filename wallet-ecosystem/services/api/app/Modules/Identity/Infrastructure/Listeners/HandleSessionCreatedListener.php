<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Events\SessionCreatedEvent;
use Illuminate\Support\Facades\Log;

final class HandleSessionCreatedListener
{
    public function __construct(
        private readonly DeviceRepositoryInterface $devices,
    ) {}

    public function handle(SessionCreatedEvent $event): void
    {
        try {
            $payload  = $event->payload();
            $deviceId = $payload['device_id'] ?? '';

            if ($deviceId === '') {
                return;
            }

            $this->devices->updateLastSeen($deviceId);
        } catch (\Throwable $e) {
            Log::warning('HandleSessionCreatedListener failed to update last_seen_at', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
