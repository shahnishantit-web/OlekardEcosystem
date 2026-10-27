<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentDisputeCaseStatus;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * T043: Scheduled job — scans open dispute cases with a response_deadline that
 * falls within the next 24 hours and emits a warning log entry.
 *
 * Scheduled in routes/console.php: Schedule::job(MonitorDisputeDeadlinesJob::class)->hourly();
 *
 * Financial integrity note: This job is READ-ONLY. It never mutates case state
 * or ledger entries — it only emits observability signals for operator runbooks.
 */
final class MonitorDisputeDeadlinesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
    }

    public function handle(PaymentDisputeCaseRepositoryInterface $caseRepository): void
    {
        $cutoff = Carbon::now()->addHours(24)->toIso8601String();

        $paginator = $caseRepository->listFiltered(
            filters:  [
                'deadline_before' => $cutoff,
                'status'          => PaymentDisputeCaseStatus::UnderReview->value,
            ],
            page:     1,
            perPage:  200,
        );

        $cases = $paginator->items();

        foreach ($cases as $case) {
            Log::warning('dispute.deadline.approaching', [
                'case_id'              => $case->id,
                'provider_code'        => $case->provider_code,
                'case_type'            => $case->case_type->value,
                'disputed_amount_minor' => $case->disputed_amount_minor,
                'response_deadline'    => $case->response_deadline?->toIso8601String(),
                'hours_remaining'      => $case->response_deadline
                    ? round(Carbon::now()->diffInMinutes($case->response_deadline) / 60, 1)
                    : null,
            ]);
        }

        Log::info('dispute.deadline_monitor.completed', [
            'approaching_deadline_count' => count($cases),
            'cutoff'                     => $cutoff,
        ]);
    }
}
