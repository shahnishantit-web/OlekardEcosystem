<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Read-only administrative summary; routes require compliance.read. */
final class ComplianceDashboardController
{
    public function __invoke(): JsonResponse
    {
        $statusRows = DB::connection('platform')->table('audit.compliance_status');
        $retention = DB::connection('platform')->table('audit.retention_policies');
        $totalStatuses = (clone $statusRows)->count();
        $highRisk = (clone $statusRows)->where('aml_risk_level', 'high')->count();
        $restricted = (clone $statusRows)->whereRaw("jsonb_array_length(active_restrictions::jsonb) > 0")->get(['user_id', 'aml_risk_level']);
        $policyCount = (clone $retention)->count();

        $openItems = $restricted->map(static fn (object $row): array => [
            'id' => (string) $row->user_id,
            'description' => 'Account has active compliance restrictions.',
            'due_date' => null,
            'urgency' => $row->aml_risk_level === 'high' ? 'high' : 'medium',
            'category' => 'account_restriction',
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'regulatory_checklist' => [
                    ['label' => 'Compliance status records available', 'status' => $totalStatuses > 0 ? 'ok' : 'warning', 'detail' => $totalStatuses . ' account records'],
                    ['label' => 'Elevated AML risk accounts', 'status' => $highRisk > 0 ? 'warning' : 'ok', 'detail' => $highRisk . ' high-risk accounts'],
                    ['label' => 'Retention policies configured', 'status' => $policyCount > 0 ? 'ok' : 'warning', 'detail' => $policyCount . ' policies'],
                ],
                'open_items' => $openItems,
                'retention_deadlines' => [],
            ],
        ]);
    }
}
