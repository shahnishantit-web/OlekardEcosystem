<?php

declare(strict_types=1);

// STUB — execution layer blocked pending AD-14 resolution.

namespace App\Modules\Payment\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PayoutAttemptQueryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $payoutAttemptId,
    ) {}

    public function handle(): never
    {
        throw new \LogicException('PayoutAttemptQueryJob is not implemented. Pending AD-14 resolution.');
    }
}
