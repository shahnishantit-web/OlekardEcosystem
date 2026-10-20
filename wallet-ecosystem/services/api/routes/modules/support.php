<?php

declare(strict_types=1);

use App\Http\Middleware\IdempotencyMiddleware;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeApproveController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeConfirmApprovalController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeConfirmRefundController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeEscalateController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeEvidenceController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeInvestigateController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeReasonController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeRefundController;
use App\Modules\Support\Infrastructure\Http\Controllers\DisputeRejectController;
use App\Modules\Support\Infrastructure\Http\Controllers\SupportTicketAssignController;
use App\Modules\Support\Infrastructure\Http\Controllers\SupportTicketController;
use App\Modules\Support\Infrastructure\Http\Controllers\SupportTicketNoteController;
use App\Modules\Support\Infrastructure\Http\Controllers\SupportTicketStatusController;
use App\Modules\Support\Infrastructure\Http\Middleware\RefundStepUpMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E22 Support & Dispute Management Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Support Tickets ─────────────────────────────────────────────────────

    Route::post('support/tickets', [SupportTicketController::class, 'store'])
        ->middleware(['identity.require-permission:support.create', IdempotencyMiddleware::class]);

    Route::get('support/tickets', [SupportTicketController::class, 'index'])
        ->middleware('identity.require-permission:support.create');

    Route::get('support/tickets/{id}', [SupportTicketController::class, 'show'])
        ->middleware('identity.require-permission:support.create');

    Route::patch('support/tickets/{id}/assign', SupportTicketAssignController::class)
        ->middleware('identity.require-permission:support.assign');

    Route::patch('support/tickets/{id}/status', SupportTicketStatusController::class)
        ->middleware('identity.require-permission:support.create');

    Route::post('support/tickets/{id}/notes', SupportTicketNoteController::class)
        ->middleware('identity.require-permission:support.create');

    // ── Disputes ─────────────────────────────────────────────────────────────

    Route::post('disputes', [DisputeController::class, 'store'])
        ->middleware(['identity.require-permission:dispute.raise', IdempotencyMiddleware::class]);

    Route::get('disputes', [DisputeController::class, 'index'])
        ->middleware('identity.require-permission:dispute.raise');

    Route::get('disputes/{id}', [DisputeController::class, 'show'])
        ->middleware('identity.require-permission:dispute.raise');

    Route::patch('disputes/{id}/reason', DisputeReasonController::class)
        ->middleware('identity.require-permission:dispute.raise');

    Route::post('disputes/{id}/evidence', [DisputeEvidenceController::class, 'store'])
        ->middleware(['identity.require-permission:dispute.raise', IdempotencyMiddleware::class]);

    Route::get('disputes/{id}/evidence/{evidenceId}/signed-url', [DisputeEvidenceController::class, 'signedUrl'])
        ->middleware('identity.require-permission:dispute.raise');

    Route::patch('disputes/{id}/investigate', DisputeInvestigateController::class)
        ->middleware('identity.require-permission:support.investigate');

    Route::patch('disputes/{id}/approve', DisputeApproveController::class)
        ->middleware(['identity.require-permission:dispute.approve', IdempotencyMiddleware::class]);

    Route::patch('disputes/{id}/confirm-approval', DisputeConfirmApprovalController::class)
        ->middleware(['identity.require-permission:dispute.approve', IdempotencyMiddleware::class]);

    Route::patch('disputes/{id}/reject', DisputeRejectController::class)
        ->middleware(['identity.require-permission:dispute.reject', IdempotencyMiddleware::class]);

    Route::post('disputes/{id}/refund', DisputeRefundController::class)
        ->middleware(['identity.require-permission:dispute.refund', RefundStepUpMiddleware::class]);

    Route::patch('disputes/{id}/confirm-refund', DisputeConfirmRefundController::class)
        ->middleware(['identity.require-permission:dispute.refund', RefundStepUpMiddleware::class]);

    Route::patch('disputes/{id}/escalate', DisputeEscalateController::class)
        ->middleware('identity.require-permission:dispute.escalate');
});
