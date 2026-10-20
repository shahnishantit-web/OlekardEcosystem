<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Enums\DocumentStatus;

// T112 ————————————————————————————————————————————————

test('ApplicationStatus has 9 cases', function () {
    expect(ApplicationStatus::cases())->toHaveCount(9);
});

test('ApplicationStatus::isEditable returns true for draft and in_progress', function () {
    expect(ApplicationStatus::Draft->isEditable())->toBeTrue();
    expect(ApplicationStatus::InProgress->isEditable())->toBeTrue();
});

test('ApplicationStatus::isEditable returns false for terminal and review states', function () {
    $nonEditable = [
        ApplicationStatus::Submitted,
        ApplicationStatus::UnderReview,
        ApplicationStatus::KycInProgress,
        ApplicationStatus::KycPassed,
        ApplicationStatus::KycFailed,
        ApplicationStatus::Approved,
        ApplicationStatus::Rejected,
    ];

    foreach ($nonEditable as $status) {
        expect($status->isEditable())->toBeFalse("Expected {$status->value} to be non-editable");
    }
});

test('ApplicationStatus::isTerminal returns true for approved, rejected, and kyc_failed', function () {
    expect(ApplicationStatus::Approved->isTerminal())->toBeTrue();
    expect(ApplicationStatus::Rejected->isTerminal())->toBeTrue();
    expect(ApplicationStatus::KycFailed->isTerminal())->toBeTrue();
});

test('ApplicationStatus::isTerminal returns false for non-terminal states', function () {
    $nonTerminal = [
        ApplicationStatus::Draft,
        ApplicationStatus::InProgress,
        ApplicationStatus::Submitted,
        ApplicationStatus::UnderReview,
        ApplicationStatus::KycInProgress,
        ApplicationStatus::KycPassed,
    ];

    foreach ($nonTerminal as $status) {
        expect($status->isTerminal())->toBeFalse("Expected {$status->value} to be non-terminal");
    }
});

test('AgentStatus has 2 cases (active, suspended)', function () {
    expect(AgentStatus::cases())->toHaveCount(2);
    $values = array_map(fn ($c) => $c->value, AgentStatus::cases());
    expect($values)->toContain('active');
    expect($values)->toContain('suspended');
});

test('DocumentStatus has 4 cases with isPersisted logic', function () {
    expect(DocumentStatus::cases())->toHaveCount(4);

    expect(DocumentStatus::PendingReview->isPersisted())->toBeTrue();
    expect(DocumentStatus::Accepted->isPersisted())->toBeTrue();
    expect(DocumentStatus::Rejected->isPersisted())->toBeTrue();
    expect(DocumentStatus::Quarantined->isPersisted())->toBeFalse();
});
