<?php

declare(strict_types=1);

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Kyc\Domain\Services\KycStateMachine;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

$machine = new KycStateMachine();

// ── Valid transitions ──────────────────────────────────────────────────────

test('initiated → documents_submitted via afterDocumentUploaded', function () use ($machine) {
    $next = $machine->transition(KycCaseStatus::Initiated, 'afterDocumentUploaded');
    expect($next)->toBe(KycCaseStatus::DocumentsSubmitted);
});

test('initiated → under_review via submit', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::Initiated, 'submit'))
        ->toBe(KycCaseStatus::UnderReview);
});

test('documents_submitted → under_review via submit', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::DocumentsSubmitted, 'submit'))
        ->toBe(KycCaseStatus::UnderReview);
});

test('under_review → approved via approve', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::UnderReview, 'approve'))
        ->toBe(KycCaseStatus::Approved);
});

test('under_review → rejected via reject', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::UnderReview, 'reject'))
        ->toBe(KycCaseStatus::Rejected);
});

test('under_review → needs_info via requestInfo', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::UnderReview, 'requestInfo'))
        ->toBe(KycCaseStatus::NeedsInfo);
});

test('rejected → initiated via resubmit', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::Rejected, 'resubmit'))
        ->toBe(KycCaseStatus::Initiated);
});

test('needs_info → initiated via resubmit', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::NeedsInfo, 'resubmit'))
        ->toBe(KycCaseStatus::Initiated);
});

test('approved → expired via expire', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::Approved, 'expire'))
        ->toBe(KycCaseStatus::Expired);
});

test('approved → initiated via triggerReverification', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::Approved, 'triggerReverification'))
        ->toBe(KycCaseStatus::Initiated);
});

test('expired → initiated via triggerReverification', function () use ($machine) {
    expect($machine->transition(KycCaseStatus::Expired, 'triggerReverification'))
        ->toBe(KycCaseStatus::Initiated);
});

// ── Invalid transitions ────────────────────────────────────────────────────

test('initiated → approve throws KycInvalidStatusTransitionException', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Initiated, 'approve'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('initiated → reject throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Initiated, 'reject'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('initiated → resubmit throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Initiated, 'resubmit'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('documents_submitted → approve throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::DocumentsSubmitted, 'approve'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('approved → submit throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Approved, 'submit'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('rejected → approve throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Rejected, 'approve'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('needs_info → approve throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::NeedsInfo, 'approve'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('expired → approve throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::Expired, 'approve'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('under_review → resubmit throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::UnderReview, 'resubmit'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('under_review → expire throws', function () use ($machine) {
    expect(fn () => $machine->transition(KycCaseStatus::UnderReview, 'expire'))
        ->toThrow(KycInvalidStatusTransitionException::class);
});

test('exception carries errorCode INVALID_CASE_STATUS', function () use ($machine) {
    try {
        $machine->transition(KycCaseStatus::Approved, 'resubmit');
    } catch (KycInvalidStatusTransitionException $e) {
        expect($e->errorCode)->toBe('INVALID_CASE_STATUS');
        expect($e->httpStatus)->toBe(422);
    }
});

test('canTransition returns true for valid pair', function () use ($machine) {
    expect($machine->canTransition(KycCaseStatus::UnderReview, 'approve'))->toBeTrue();
});

test('canTransition returns false for invalid pair', function () use ($machine) {
    expect($machine->canTransition(KycCaseStatus::Approved, 'resubmit'))->toBeFalse();
});
