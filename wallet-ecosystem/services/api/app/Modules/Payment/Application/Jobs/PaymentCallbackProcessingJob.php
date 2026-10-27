<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Infrastructure\Models\PaymentCallbackModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Retained for already queued jobs; legacy evidence has no trusted account binding. */
final class PaymentCallbackProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly string $callbackId) {}

    public function handle(): void
    {
        PaymentCallbackModel::whereKey($this->callbackId)
            ->whereNotIn('status', ['processed', 'duplicate'])
            ->update(['status' => 'dead_lettered', 'error_message' => 'quarantine:legacy_unverified_account_mapping']);
    }
}
