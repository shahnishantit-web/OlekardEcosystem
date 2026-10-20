<?php

declare(strict_types=1);

use App\Modules\Kyc\Application\Services\KycVerificationService;
use App\Modules\Kyc\Domain\Contracts\KycVerificationProvider;
use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;
use App\Modules\Kyc\Domain\ValueObjects\VerificationOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T104 ————————————————————————————————————————————————

test('kyc_verification_results has raw_response_hash and no raw_response column', function () {
    $columns = DB::connection('kyc')
        ->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'kyc' AND table_name = 'kyc_verification_results'");

    $colNames = array_map(fn ($c) => $c->column_name, $columns);

    expect($colNames)->toContain('raw_response_hash');
    expect($colNames)->not->toContain('raw_response');
});

test('provider response never stored in audit_events', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'provtest-001'));
    $this->kycUploadDocument($case->id, 'national_id');
    $this->kycSubmitCase($case->id);

    $sensitiveData = 'SENSITIVE_PROVIDER_DATA_XYZ';

    $this->app->bind(KycVerificationProvider::class, fn () => new class ($sensitiveData) implements KycVerificationProvider {
        public function __construct(private readonly string $sensitive) {}
        public function verify(\App\Modules\Kyc\Infrastructure\Models\KycCaseModel $case): VerificationOutcome
        {
            return new VerificationOutcome(
                outcome:           KycVerificationOutcome::ManualReviewRequired,
                providerReference: 'prov-ref-pii-test',
                rawResponse:       ['confidential' => $this->sensitive],
                confidenceScore:   null,
            );
        }
        public function providerName(): string { return 'pii-test-provider'; }
    });

    app(KycVerificationService::class)->performVerification($case->id);

    // raw_response_hash is stored, not the raw response
    $result = DB::connection('kyc')
        ->table('kyc.kyc_verification_results')
        ->where('kyc_case_id', $case->id)
        ->first();

    expect($result->raw_response_hash)->toHaveLength(64);

    // Sensitive data must NOT appear in audit_events
    $auditRaw = DB::connection('platform')
        ->table('platform.audit_events')
        ->get()
        ->map(fn ($r) => $r->metadata)
        ->implode('');

    expect($auditRaw)->not->toContain($sensitiveData);
});
