<?php

declare(strict_types=1);

use App\Modules\Agent\Infrastructure\Http\Controllers\AgentApplicationController;
use App\Modules\Agent\Infrastructure\Http\Controllers\AgentCustomerController;
use App\Modules\Agent\Infrastructure\Http\Controllers\AgentLifecycleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E12 Agent Management Routes
|--------------------------------------------------------------------------
| All endpoints are under /v1/.
| Auth: identity.auth (JWT required).
| Permission: identity.require-permission:{slug}.
| Routes are loaded by AgentServiceProvider::boot().
*/

Route::prefix('v1')->middleware(['identity.auth'])->group(static function (): void {

    // ── Agent Application Onboarding ────────────────────────────────────────

    // T029: US-AGENT-001 — Create agent application (idempotent)
    Route::post('agent-applications', [AgentApplicationController::class, 'store'])
        ->middleware('identity.require-permission:agent.application.create');

    // T036: US-AGENT-002 — Update personal information
    Route::patch('agent-applications/{id}/personal-information', [AgentApplicationController::class, 'updatePersonalInfo'])
        ->middleware('identity.require-permission:agent.application.read');

    // T042: US-AGENT-003 — Update business information
    Route::patch('agent-applications/{id}/business-information', [AgentApplicationController::class, 'updateBusinessInfo'])
        ->middleware('identity.require-permission:agent.application.read');

    // T049: US-AGENT-004 — Upload document
    Route::post('agent-applications/{id}/documents', [AgentApplicationController::class, 'uploadDocument'])
        ->middleware('identity.require-permission:agent.application.read');

    // T056: US-AGENT-005 — Trigger KYC verification
    Route::post('agent-applications/{id}/kyc', [AgentApplicationController::class, 'triggerKyc'])
        ->middleware('identity.require-permission:agent.application.read');

    // T059: US-AGENT-006 — Approve application (Admin)
    Route::post('agent-applications/{id}/approve', [AgentLifecycleController::class, 'approve'])
        ->middleware('identity.require-permission:agent.approve');

    // T067: US-AGENT-007 — Reject application (Admin)
    Route::post('agent-applications/{id}/reject', [AgentLifecycleController::class, 'reject'])
        ->middleware('identity.require-permission:agent.reject');

    // ── Agent Lifecycle ──────────────────────────────────────────────────────

    // T074: US-AGENT-008 — Suspend agent (Admin)
    Route::post('agents/{id}/suspend', [AgentLifecycleController::class, 'suspend'])
        ->middleware('identity.require-permission:agent.suspend');

    // T077: US-AGENT-009 — Reactivate agent (Admin)
    Route::post('agents/{id}/reactivate', [AgentLifecycleController::class, 'reactivate'])
        ->middleware('identity.require-permission:agent.reactivate');

    // ── Agent Customer Management ────────────────────────────────────────────

    // T081: US-AGENT-010 — Search agent customers
    Route::get('agents/{id}/customers', [AgentCustomerController::class, 'search'])
        ->middleware('identity.require-permission:agent.customer.search');

    // T086: US-AGENT-011 — Register customer via agent (idempotent)
    Route::post('agents/{id}/customers', [AgentCustomerController::class, 'register'])
        ->middleware('identity.require-permission:agent.customer.register');

    // T091: US-AGENT-012 — Initiate customer KYC via agent
    Route::post('agents/{id}/customers/{cid}/kyc', [AgentCustomerController::class, 'initiateCustomerKyc'])
        ->middleware('identity.require-permission:agent.customer.kyc.initiate');

    // T096: US-AGENT-013 — View customer wallet via agent
    Route::get('agents/{id}/customers/{cid}/wallet', [AgentCustomerController::class, 'viewWallet'])
        ->middleware('identity.require-permission:agent.customer.wallet.read');

    // T100: US-AGENT-014 — View customer transactions via agent
    Route::get('agents/{id}/customers/{cid}/transactions', [AgentCustomerController::class, 'viewTransactions'])
        ->middleware('identity.require-permission:agent.customer.transactions.read');
});
