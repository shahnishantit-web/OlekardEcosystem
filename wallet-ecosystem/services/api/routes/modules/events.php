<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Http\Controllers\ComplimentaryApprovalController;
use App\Modules\Events\Infrastructure\Http\Controllers\CustomerEventCatalogueController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventAdmissionController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventAssignmentController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventAuthoringController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventCancellationController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventCurrencyController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventInventoryHoldController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventOrderController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventPocSettlementController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventReportController;
use App\Modules\Events\Infrastructure\Http\Controllers\EventSponsorController;
use App\Modules\Events\Infrastructure\Http\Controllers\FreeEventRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/events')->middleware(['identity.auth'])->group(static function (): void {
    Route::get('customer/catalogue', [CustomerEventCatalogueController::class, 'index']);
    Route::get('customer/invitations', [CustomerEventCatalogueController::class, 'invitations']);
    Route::get('sponsor-reports', [EventSponsorController::class, 'assignedReports']);
    Route::get('my-gate-assignments', [EventAdmissionController::class, 'myGateAssignments']);
    Route::get('organisations/{organisationId}/drafts', [EventAuthoringController::class, 'index']);
    Route::get('organisations/{organisationId}/members', [EventAssignmentController::class, 'organisationMembers']);
    Route::get('organisations/{organisationId}/sponsors', [EventSponsorController::class, 'index']);
    Route::get('organisations/{organisationId}/sponsor-report-viewers', [EventSponsorController::class, 'reportViewers']);
    Route::get('{eventId}/sponsor-report', [EventSponsorController::class, 'report']);
    Route::get('{eventId}/sponsor-report.csv', [EventSponsorController::class, 'exportReport']);
    Route::get('{eventId}/sponsors', [EventSponsorController::class, 'eventSponsors']);
    Route::get('{eventId}/report', [EventReportController::class, 'show']);
    Route::get('{eventId}/report.csv', [EventReportController::class, 'export']);
    Route::get('{eventId}/poc-settlements', [EventPocSettlementController::class, 'index']);
    Route::get('{eventId}/poc-refunds', [EventPocSettlementController::class, 'refunds']);
    Route::get('{eventId}/poc-event-end', [EventPocSettlementController::class, 'eventEnd']);
    Route::get('{eventId}/invitation-customers', [EventAssignmentController::class, 'invitationCustomers']);
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
    Route::post('{eventId}/inventory-holds', [EventInventoryHoldController::class, 'store']);
    Route::post('{eventId}/free-registrations', [FreeEventRegistrationController::class, 'register']);
    Route::post('customer/invitations/{invitationId}/accept', [FreeEventRegistrationController::class, 'acceptCustomerInvitation']);
    Route::post('{eventId}/invitations', [FreeEventRegistrationController::class, 'createInvitation']);
    Route::delete('invitations/{invitationId}', [FreeEventRegistrationController::class, 'revokeInvitation']);
    Route::post('{eventId}/quotes', [EventOrderController::class, 'quote']);
    Route::post('{eventId}/orders', [EventOrderController::class, 'create']);
    Route::post('orders/{orderId}/payment-intent', [EventOrderController::class, 'paymentIntent']);
    Route::post('organisations/{organisationId}/drafts', [EventAuthoringController::class, 'store']);
    Route::post('organisations/{organisationId}/sponsors', [EventSponsorController::class, 'store']);
    Route::patch('organisations/{organisationId}/sponsors/{sponsorId}', [EventSponsorController::class, 'update'])
        ->middleware('idempotency:require-identity');
    Route::post('{eventId}/sponsors', [EventSponsorController::class, 'link']);
    Route::delete('{eventId}/sponsors/{sponsorId}', [EventSponsorController::class, 'unlink'])
        ->middleware('idempotency:require-identity');
    Route::post('{eventId}/poc-settlements', [EventPocSettlementController::class, 'request']);
    Route::post('{eventId}/poc-simulate-end', [EventPocSettlementController::class, 'simulateEventEnd']);
    Route::post('poc-settlements/{settlementId}/approve', [EventPocSettlementController::class, 'approve']);
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
    Route::post('{eventId}/publish', [EventAuthoringController::class, 'publish']);
    Route::post('{eventId}/resume-sales', [EventAuthoringController::class, 'resumeSales']);
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
