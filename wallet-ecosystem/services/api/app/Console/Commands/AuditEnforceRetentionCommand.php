<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\RetentionPolicyModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditEnforceRetentionCommand extends Command
{
    protected $signature   = 'audit:enforce-retention';
    protected $description = 'Enforce data retention policies: archive or purge audit records older than their minimum retention window.';

    private const BATCH_SIZE = 500;

    public function handle(AuditModuleContract $audit): int
    {
        $today    = CarbonImmutable::today();
        $policies = RetentionPolicyModel::all();
        $summary  = [];

        foreach ($policies as $policy) {
            if ($policy->enforcement_hold_until !== null && $policy->enforcement_hold_until->gt($today)) {
                $this->line("Skipping {$policy->data_category}: grace period until {$policy->enforcement_hold_until->toDateString()}");
                continue;
            }

            [$table, $archiveTable] = $this->resolveTable($policy->data_category);
            if ($table === null) {
                $this->warn("No table mapping for category: {$policy->data_category}");
                continue;
            }

            $cutoff    = $today->subDays($policy->minimum_retention_days)->toDateTimeString();
            $processed = 0;
            $action    = $policy->post_retention_action;

            while (true) {
                $ids = DB::connection('platform')
                    ->table($table)
                    ->where('created_at', '<', $cutoff)
                    ->limit(self::BATCH_SIZE)
                    ->pluck('id')
                    ->toArray();

                if (empty($ids)) {
                    break;
                }

                if ($action === 'archive' && $archiveTable !== null) {
                    foreach ($ids as $id) {
                        DB::connection('platform')->statement(
                            "INSERT INTO {$archiveTable} SELECT *, now() AS archived_at FROM {$table} WHERE id = ?",
                            [$id]
                        );
                        DB::connection('platform')->table($table)->where('id', $id)->delete();
                    }
                } else {
                    DB::connection('platform')->table($table)->whereIn('id', $ids)->delete();
                }

                $processed += count($ids);

                $audit->record(
                    AuditEntry::make(
                        category:      AuditEventCategory::Admin,
                        eventType:     'admin.retention.enforced',
                        actorType:     AuditActorType::System,
                        action:        "Retention enforcement batch: {$policy->data_category} ({$action})",
                        outcome:       AuditOutcome::Success,
                        correlationId: 'retention-'.$today->format('Ymd'),
                    )->withMetadata([
                        'data_category' => $policy->data_category,
                        'action'        => $action,
                        'batch_count'   => count($ids),
                    ])
                );
            }

            $summary[$policy->data_category] = ['action' => $action, 'processed' => $processed];
            $this->line("Processed {$processed} records for {$policy->data_category} ({$action}).");
        }

        $audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'admin.retention.run_completed',
                actorType:     AuditActorType::System,
                action:        'Retention enforcement run completed',
                outcome:       AuditOutcome::Success,
                correlationId: 'retention-'.$today->format('Ymd'),
            )->withMetadata(['summary' => $summary])
        );

        $this->info('audit:enforce-retention complete.');

        return self::SUCCESS;
    }

    private function resolveTable(string $category): array
    {
        return match (true) {
            str_starts_with($category, 'audit.')        => ['audit.audit_events', 'audit.audit_events_archive'],
            str_starts_with($category, 'compliance.')   => ['audit.compliance_history', null],
            default                                     => [null, null],
        };
    }
}
