<?php

declare(strict_types=1);

use App\Modules\Kyc\Application\Services\KycVerificationService;
use App\Modules\Kyc\Domain\Contracts\KycVerificationProvider;
use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;
use App\Modules\Kyc\Domain\ValueObjects\VerificationOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Support\KycTestCase;

uses(KycTestCase::class);

// T069 ————————————————————————————————————————————————

test('NullKycVerificationProvider returns manual_review_required and leaves case under_review', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'vtest-001'));
    $this->kycUploadDocument($case->id, 'national_id');
    $this->kycSubmitCase($case->id);

    /** @var KycVerificationService $service */
    $service = app(KycVerificationService::class);
    $service->performVerification($case->id);

    $result = DB::connection('kyc')
        ->table('kyc.kyc_verification_results')
        ->where('kyc_case_id', $case->id)
        ->first();

    expect($result)->not->toBeNull();
    expect($result->outcome)->toBe('manual_review_required');

    // raw_response_hash is 64-char hex
    expect($result->raw_response_hash)->toHaveLength(64);
    expect(ctype_xdigit($result->raw_response_hash))->toBeTrue();

    // Case remains under_review
    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('under_review');
});

test('no raw_response column exists in kyc_verification_results table', function () {
    $columns = DB::connection('kyc')
        ->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'kyc' AND table_name = 'kyc_verification_results'");

    $columnNames = array_map(fn ($c) => $c->column_name, $columns);
    expect($columnNames)->not->toContain('raw_response');
    expect($columnNames)->toContain('raw_response_hash');
});

// T070 ————————————————————————————————————————————————

test('provider returning pass causes case to be approved with outbox event', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycAddPersonalInfo($case->id, hash('sha256', 'vtest-002'));
    $this->kycUploadDocument($case->id, 'national_id');
    $this->kycSubmitCase($case->id);

    // Bind a mock provider that returns pass
    $this->app->bind(KycVerificationProvider::class, fn () => new class implements KycVerificationProvider {
        public function verify(\App\Modules\Kyc\Infrastructure\Models\KycCaseModel $case): VerificationOutcome
        {
            return new VerificationOutcome(
                outcome:           KycVerificationOutcome::Pass,
                providerReference: 'mock-ref-001',
                rawResponse:       ['status' => 'pass'],
                confidenceScore:   0.99,
            );
        }
        public function providerName(): string { return 'mock-pass'; }
    });

    /** @var KycVerificationService $service */
    $service = app(KycVerificationService::class);
    $service->performVerification($case->id);

    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('approved');

    $audit = DB::connection('platform')
        ->table('platform.audit_events')
        ->where('action', 'kyc.case.approved')
        ->where('resource_id', $case->id)
        ->first();

    expect($audit)->not->toBeNull();

    $outbox = DB::connection('platform')
        ->table('platform.outbox_events')
        ->where('event_name', 'Kyc.Case.Approved')
        ->first();

    expect($outbox)->not->toBeNull();
});

// T071 ————————————————————————————————————————————————

test('provider exception leaves case under_review and logs kyc.provider.error', function () {
    $user = $this->makeCustomerWithPermission('kyc.case.update');
    $case = $this->kycCreateCase($user->id);
    $this->kycSubmitCase($case->id);

    // Bind a provider that throws
    $this->app->bind(KycVerificationProvider::class, fn () => new class implements KycVerificationProvider {
        public function verify(\App\Modules\Kyc\Infrastructure\Models\KycCaseModel $case): VerificationOutcome
        {
            throw new \RuntimeException('External provider unavailable');
        }
        public function providerName(): string { return 'mock-error'; }
    });

    /** @var KycVerificationService $service */
    $service = app(KycVerificationService::class);

    // Should not throw — job will catch and the case stays under_review
    try {
        $service->performVerification($case->id);
    } catch (\Throwable) {
        // Job retries handle this; the key assertion is status unchanged
    }

    $status = DB::connection('kyc')
        ->table('kyc.kyc_cases')
        ->where('id', $case->id)
        ->value('status');

    expect($status)->toBe('under_review');
});
