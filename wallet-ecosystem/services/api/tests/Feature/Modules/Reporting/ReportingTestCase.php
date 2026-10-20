<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reporting;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use App\Modules\Reporting\Infrastructure\Models\ReportJobModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

abstract class ReportingTestCase extends LedgerTestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    protected string $definitionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureReportDefinition('wallet-report', 'admin.read');
        $this->ensureReportDefinition('reconciliation-report', 'finance.reports');
        $this->ensureReportDefinition('kyc-report', 'compliance.reports', true);
        $this->ensureReportDefinition('user-report', 'admin.read', true);
        $this->ensureReportDefinition('agent-report', 'admin.read');
        $this->ensureReportDefinition('financial-summary', 'finance.reports');
        $this->ensureReportDefinition('risk-report', 'compliance.reports');
        $this->ensureReportDefinition('transaction-report', 'admin.read');
    }

    protected function ensureReportDefinition(
        string $type,
        string $requiredPermission,
        bool   $piiSensitive = false,
    ): void {
        ReportDefinitionModel::updateOrCreate(
            ['type' => $type],
            [
                'name'                => ucfirst(str_replace('-', ' ', $type)),
                'description'         => "Test definition for {$type}",
                'required_permission' => $requiredPermission,
                'pii_sensitive'       => $piiSensitive,
                'filter_schema'       => [
                    'type'       => 'object',
                    'required'   => ['date_range_start', 'date_range_end'],
                    'properties' => [
                        'date_range_start' => ['type' => 'string'],
                        'date_range_end'   => ['type' => 'string'],
                    ],
                ],
                'is_active' => true,
            ]
        );
    }

    protected function seedActiveJob(string $userId, string $type = 'wallet-report'): string
    {
        $jobId = (string) Uuid::uuid7();
        $defId = ReportDefinitionModel::where('type', $type)->value('id')
            ?? $this->seedDefinitionId($type);

        DB::connection('platform')->table('reporting.report_jobs')->insert([
            'id'                   => $jobId,
            'definition_id'        => $defId,
            'report_type'          => $type,
            'requested_by_user_id' => $userId,
            'correlation_id'       => (string) Uuid::uuid7(),
            'filters'              => json_encode(['date_range_start' => '2026-01-01', 'date_range_end' => '2026-12-31']),
            'status'               => ReportJobStatus::Requested->value,
            'pii_access'           => false,
            'created_at'           => now(),
        ]);

        return $jobId;
    }

    private function seedDefinitionId(string $type): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('reporting.report_definitions')->insertOrIgnore([
            'id'                  => $id,
            'type'                => $type,
            'name'                => $type,
            'description'         => $type,
            'required_permission' => 'admin.read',
            'pii_sensitive'       => false,
            'filter_schema'       => json_encode(['type' => 'object', 'required' => ['date_range_start', 'date_range_end'], 'properties' => []]),
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        return $id;
    }
}
