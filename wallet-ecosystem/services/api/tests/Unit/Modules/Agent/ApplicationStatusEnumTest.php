<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;

// T032 — US-AGENT-001: ApplicationStatus enum tests

test('all 9 application status cases exist', function () {
    $expected = [
        'draft', 'in_progress', 'submitted', 'kyc_in_progress',
        'kyc_passed', 'kyc_failed', 'under_review', 'approved', 'rejected',
    ];

    $values = array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::cases());
    expect($values)->toBe($expected);
});

test('isEditable returns true only for Draft and InProgress', function () {
    expect(ApplicationStatus::Draft->isEditable())->toBeTrue();
    expect(ApplicationStatus::InProgress->isEditable())->toBeTrue();

    $nonEditable = [
        ApplicationStatus::Submitted,
        ApplicationStatus::KycInProgress,
        ApplicationStatus::KycPassed,
        ApplicationStatus::KycFailed,
        ApplicationStatus::UnderReview,
        ApplicationStatus::Approved,
        ApplicationStatus::Rejected,
    ];

    foreach ($nonEditable as $status) {
        expect($status->isEditable())->toBeFalse("Expected {$status->value} to be non-editable");
    }
});

test('isEditable returns false for UnderReview', function () {
    expect(ApplicationStatus::UnderReview->isEditable())->toBeFalse();
});

test('isTerminal returns true for Approved and Rejected', function () {
    expect(ApplicationStatus::Approved->isTerminal())->toBeTrue();
    expect(ApplicationStatus::Rejected->isTerminal())->toBeTrue();
    expect(ApplicationStatus::KycFailed->isTerminal())->toBeTrue();
});

test('isTerminal returns false for non-terminal statuses', function () {
    $nonTerminal = [
        ApplicationStatus::Draft,
        ApplicationStatus::InProgress,
        ApplicationStatus::Submitted,
        ApplicationStatus::KycInProgress,
        ApplicationStatus::KycPassed,
        ApplicationStatus::UnderReview,
    ];

    foreach ($nonTerminal as $status) {
        expect($status->isTerminal())->toBeFalse("Expected {$status->value} to be non-terminal");
    }
});

test('from() resolves string values back to enum cases', function () {
    expect(ApplicationStatus::from('draft'))->toBe(ApplicationStatus::Draft);
    expect(ApplicationStatus::from('under_review'))->toBe(ApplicationStatus::UnderReview);
    expect(ApplicationStatus::from('kyc_in_progress'))->toBe(ApplicationStatus::KycInProgress);
});
