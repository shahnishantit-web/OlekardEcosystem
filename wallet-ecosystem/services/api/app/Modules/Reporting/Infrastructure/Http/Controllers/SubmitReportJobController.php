<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Domain\Enums\ReportType;
use App\Modules\Reporting\Infrastructure\Http\Requests\SubmitReportJobRequest;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportJobSummaryResource;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateAgentReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateCommissionReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateFinancialSummaryReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateKycReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateReconciliationReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateRiskReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateSettlementReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateTransactionReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateUserReportJob;
use App\Modules\Reporting\Infrastructure\Jobs\GenerateWalletReportJob;
use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class SubmitReportJobController
{
    public function __construct(
        private readonly ReportJobApplicationService $service,
        private readonly IdentityModuleContract      $identity,
    ) {}

    public function __invoke(SubmitReportJobRequest $request): JsonResponse
    {
        $type          = $request->resolvedType();
        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();

        $definition = ReportDefinitionModel::active()->where('type', $type->value)->firstOrFail();
        if (!$this->identity->hasPermission($userId, $definition->required_permission)) {
            return response()->json(
                ['message' => 'You do not have permission to generate this report type.'],
                403
            );
        }

        $job = $this->service->submit(
            userId:        $userId,
            type:          $type,
            filters:       $request->validated('filters'),
            piiAccess:     $request->piiAccess(),
            correlationId: $correlationId,
        );

        $this->dispatchJobHandler($type, $job->id, $correlationId);

        return (new ReportJobSummaryResource($job))
            ->response()
            ->setStatusCode(202);
    }

    private function dispatchJobHandler(ReportType $type, string $jobId, string $correlationId): void
    {
        match ($type) {
            ReportType::WalletReport         => GenerateWalletReportJob::dispatch($jobId, $correlationId),
            ReportType::TransactionReport    => GenerateTransactionReportJob::dispatch($jobId, $correlationId),
            ReportType::UserReport           => GenerateUserReportJob::dispatch($jobId, $correlationId),
            ReportType::AgentReport          => GenerateAgentReportJob::dispatch($jobId, $correlationId),
            ReportType::CommissionReport     => GenerateCommissionReportJob::dispatch($jobId, $correlationId),
            ReportType::KycReport            => GenerateKycReportJob::dispatch($jobId, $correlationId),
            ReportType::ReconciliationReport => GenerateReconciliationReportJob::dispatch($jobId, $correlationId),
            ReportType::SettlementReport     => GenerateSettlementReportJob::dispatch($jobId, $correlationId),
            ReportType::RiskReport           => GenerateRiskReportJob::dispatch($jobId, $correlationId),
            ReportType::FinancialSummary     => GenerateFinancialSummaryReportJob::dispatch($jobId, $correlationId),
        };
    }
}
