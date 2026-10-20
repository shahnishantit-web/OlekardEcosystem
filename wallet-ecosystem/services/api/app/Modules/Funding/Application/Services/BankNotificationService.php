<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Infrastructure\Models\BankReconciliationModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Idempotency guard for inbound bank notifications.
 *
 * CC-E08-004: A bank notification with a previously-seen bank_notification_id
 * MUST NOT trigger a second credit posting. This service checks for duplicates
 * and records the notification in funding_bank_reconciliations.
 */
final class BankNotificationService
{
    public function __construct(
        private readonly BankMatchingService $matchingService,
    ) {}

    /**
     * @param array{
     *   bank_notification_id: string,
     *   bank_reference_code:  string,
     *   amount:               int,
     *   currency_code:        string,
     *   correlation_id:       string,
     *   raw_payload:          array,
     * } $notification
     */
    public function handle(array $notification): void
    {
        $notificationId = $notification['bank_notification_id'];

        // Idempotency: check if already processed
        $alreadyProcessed = BankReconciliationModel::where('bank_notification_id', $notificationId)->exists();

        if ($alreadyProcessed) {
            return; // Duplicate — safe to ignore
        }

        DB::transaction(function () use ($notification, $notificationId): void {
            // Serialize the same notification before crediting or recording it.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$notificationId]);
            if (BankReconciliationModel::where('bank_notification_id', $notificationId)->exists()) return;
            $this->matchingService->match($notification);
        });
    }
}
