<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Http\Controllers\EventAdmissionController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventAssignmentController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventAuthoringController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventCurrencyController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventCancellationController;
use App\Modules\Events\Infrastructure\Http\Controllers\ComplimentaryApprovalController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventOrderController;
use App\Modules\Events\Infrastructure\Http\Controllers\FreeEventRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/events')->middleware(['identity.auth'])->group(static function (): void {
    Route::get('tickets', [EventAdmissionController::class, 'tickets']);
    Route::get('tickets/{ticketId}', [EventAdmissionController::class, 'ticket']);
    Route::post('tickets/{ticketId}/replace-credential', [EventAdmissionController::class, 'replaceCredential'])
        ->middleware('idempotency:require-identity');
    Route::post('{eventId}/gates/{gateId}/validate', [EventAdmissionController::class, 'validate']);
    Route::get('{eventId}/attendance', [EventAdmissionController::class, 'attendance']);
    Route::get('{eventId}/cancellation-requests', [EventCancellationController::class, 'requests']);
    Route::get('{eventId}/complimentary-approvals', [ComplimentaryApprovalController::class, 'index']);
    Route::get('{eventId}', [EventAuthoringController::class, 'show']);
    Route::get('{eventId}/gates', [EventAssignmentController::class, 'indexGates']);
    Route::get('organisations/{organisationId}/assignments/issued-by/{issuerActorId}', [EventAssignmentController::class, 'issuedBy']);
});

Route::prefix('v1/events')->middleware(['identity.auth', 'idempotency:require-identity'])->group(static function (): void {
    Route::post('{eventId}/free-registrations', [FreeEventRegistrationController::class, 'register']);
    Route::post('{eventId}/invitations', [FreeEventRegistrationController::class, 'createInvitation']);
    Route::delete('invitations/{invitationId}', [FreeEventRegistrationController::class, 'revokeInvitation']);
    Route::post('{eventId}/quotes', [EventOrderController::class, 'quote']);
    Route::post('{eventId}/orders', [EventOrderController::class, 'create']);
    Route::post('orders/{orderId}/payment-intent', [EventOrderController::class, 'paymentIntent']);
    Route::post('organisations/{organisationId}/drafts', [EventAuthoringController::class, 'store']);
    Route::put('{eventId}', [EventAuthoringController::class, 'update']);
    Route::post('{eventId}/submit-review', [EventAuthoringController::class, 'submit']);
    Route::post('{eventId}/cancellation-requests', [EventCancellationController::class, 'request']);
    Route::post('{eventId}/cancellation-requests/{requestId}/approve', [EventCancellationController::class, 'approve']);
    Route::post('{eventId}/cancellation-requests/{requestId}/reject', [EventCancellationController::class, 'reject']);
    Route::post('{eventId}/complimentary-approval-requests', [ComplimentaryApprovalController::class, 'request']);
    Route::post('{eventId}/complimentary-approvals/{approvalId}/approve', [ComplimentaryApprovalController::class, 'approve']);
    Route::delete('{eventId}/complimentary-approvals/{approvalId}', [ComplimentaryApprovalController::class, 'revoke']);
    Route::post('{eventId}/approve-prices', [EventAuthoringController::class, 'approvePrices']);
    Route::post('{eventId}/approve-review', [EventAuthoringController::class, 'approveReview']);
    Route::post('{eventId}/reject', [EventAuthoringController::class, 'reject']);
    Route::post('{eventId}/gates', [EventAssignmentController::class, 'storeGate']);
    Route::post('{eventId}/currency-assignment', [EventCurrencyController::class, 'assign']);
    Route::post('gates/{gateId}/validation-assignments', [EventAssignmentController::class, 'storeGateValidationAssignment']);
    Route::post('organisations/{organisationId}/assignments', [EventAssignmentController::class, 'storeOrganisation']);
    Route::post('{eventId}/assignments', [EventAssignmentController::class, 'storeEvent']);
    Route::delete('assignments/{assignmentId}', [EventAssignmentController::class, 'revoke']);
    Route::post('organisations/{organisationId}/assignment-administrators', [EventAssignmentController::class, 'delegateAdministrator']);
    Route::delete('assignment-administrators/{administratorId}', [EventAssignmentController::class, 'revokeAdministrator']);
});

Route::prefix('v1/events')->middleware(['identity.auth'])->group(static function (): void {
    Route::get('orders/{orderId}', [EventOrderController::class, 'show']);
});
