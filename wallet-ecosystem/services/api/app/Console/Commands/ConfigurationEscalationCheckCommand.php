<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ConfigurationEscalationCheckCommand extends Command
{
    protected $signature   = 'configuration:escalation-check';

    protected $description = 'Emit audit events for configuration change requests pending approval beyond the escalation threshold.';

    public function handle(AuditServiceInterface $auditService): int
    {
        $thresholdHours = (int) config('configuration.escalation_threshold_hours', 24);
        $cutoff         = Carbon::now()->subHours($thresholdHours);
        $correlationId  = (string) Uuid::uuid7();

        $staleRequests = DB::connection('platform')
            ->table('platform.configuration_change_requests')
            ->where('status', 'pending_approval')
            ->where('submitted_at', '<=', $cutoff)
            ->get(['id', 'config_key', 'domain', 'maker_id', 'submitted_at']);

        foreach ($staleRequests as $request) {
            $auditService->record(new AuditEventData(
                action:        'configuration.change_request.escalated',
                actorType:     'SYSTEM',
                resourceType:  'ConfigurationChangeRequest',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $request->id,
                afterState:    [
                    'config_key'   => $request->config_key,
                    'domain'       => $request->domain,
                    'maker_id'     => $request->maker_id,
                    'submitted_at' => $request->submitted_at,
                    'hours_pending' => Carbon::parse($request->submitted_at)->diffInHours(Carbon::now()),
                ],
            ));

            $this->line("Escalated: {$request->id} ({$request->config_key})");
        }

        $this->info("Escalation check complete. {$staleRequests->count()} stale request(s) escalated.");

        return self::SUCCESS;
    }
}
