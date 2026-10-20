<?php

declare(strict_types=1);

namespace App\Modules\Qr\Application\Jobs;

use App\Modules\Qr\Domain\Enums\QrCodeStatus;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ReleaseExpiredQrReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            $count = QrCodeModel::where('status', QrCodeStatus::Reserved->value)
                ->where('reserved_until', '<', now())
                ->update([
                    'status'                  => QrCodeStatus::Active->value,
                    'reserved_by_customer_id' => null,
                    'reserved_until'          => null,
                ]);

            if ($count > 0) {
                Log::info('QR reservations released', ['count' => $count]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to release expired QR reservations', ['error' => $e->getMessage()]);
        }
    }
}
