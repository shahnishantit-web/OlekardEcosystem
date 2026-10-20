<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application\Services;

use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Contracts\KycVerificationProvider;
use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class KycVerificationService
{
    public function __construct(
        private readonly KycCaseRepositoryInterface $repository,
        private readonly KycVerificationProvider    $provider,
        private readonly KycCaseService             $caseService,
        private readonly OutboxEventPublisher       $outbox,
    ) {}

    public function performVerification(string $caseId): void
    {
        $case = $this->repository->findById($caseId);
        if ($case === null || $case->status !== KycCaseStatus::UnderReview) {
            return;
        }

        $correlationId = (string) Uuid::uuid7();

        // Call the provider (passing typed KycCaseModel as per T011 interface)
        $outcome = $this->provider->verify($case);

        // SEC-E05-003: hash raw response — NEVER store it
        $rawResponseHash = hash_hmac(
            'sha256',
            json_encode($outcome->rawResponse),
            (string) config('app.key'),
        );

        DB::connection('kyc')->transaction(function () use ($case, $outcome, $rawResponseHash, $caseId, $correlationId): void {
            DB::connection('kyc')->table('kyc.kyc_verification_results')->insert([
                'id'                 => (string) Uuid::uuid7(),
                'kyc_case_id'        => $caseId,
                'provider_name'      => $this->provider->providerName(),
                'provider_reference' => $outcome->providerReference,
                'outcome'            => $outcome->outcome->value,
                'confidence_score'   => $outcome->confidenceScore,
                'raw_response_hash'  => $rawResponseHash,
                'created_at'         => now(),
            ]);
        });

        Log::info('kyc.provider.call', [
            'case_id'  => $caseId,
            'provider' => $this->provider->providerName(),
            'outcome'  => $outcome->outcome->value,
        ]);

        if ($outcome->outcome === KycVerificationOutcome::Pass) {
            $this->caseService->approveCase(
                caseId:        $caseId,
                reviewerId:    null,
                correlationId: $correlationId,
                method:        'automated',
                actorType:     'system',
            );
        }
        // fail / manual_review_required / error: case remains under_review for manual processing
    }
}
