<?php

declare(strict_types=1);

use App\Modules\AdminApp\Infrastructure\Http\Controllers\AgentController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\ApprovalController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\ComplianceController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\DashboardController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\KycController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\ReconciliationController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\TransactionController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\UserController;
use App\Modules\AdminApp\Infrastructure\Http\Controllers\WalletController;
use App\Modules\AdminApp\Infrastructure\Http\Middleware\AddCorrelationIdHeader;
use App\Modules\AdminApp\Infrastructure\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin-app')->middleware(['identity.auth', AddCorrelationIdHeader::class])->group(function (): void {

    // --- Dashboards (US-ADMINAPP-001/002/003) ---
    Route::get('/dashboard/operational', [DashboardController::class, 'operational'])
        ->middleware('identity.require-permission:adminapp.dashboard.operational');

    Route::get('/dashboard/financial', [DashboardController::class, 'financial'])
        ->middleware('identity.require-permission:adminapp.dashboard.financial');

    Route::get('/dashboard/risk', [DashboardController::class, 'risk'])
        ->middleware('identity.require-permission:adminapp.dashboard.risk');

    // --- Users (US-ADMINAPP-004/005/006) ---
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.users.read');

    Route::get('/users/{user_id}', [UserController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.users.read');

    Route::patch('/users/{user_id}/status', [UserController::class, 'updateStatus'])
        ->middleware(['identity.require-permission:adminapp.users.write', RequireIdempotencyKey::class]);

    Route::get('/roles', [UserController::class, 'listRoles'])
        ->middleware('identity.require-permission:adminapp.roles.manage');

    Route::post('/roles', [UserController::class, 'createRole'])
        ->middleware(['identity.require-permission:adminapp.roles.manage', RequireIdempotencyKey::class]);

    Route::get('/roles/{role_id}', [UserController::class, 'showRole'])
        ->middleware('identity.require-permission:adminapp.roles.manage');

    Route::patch('/roles/{role_id}/permissions', [UserController::class, 'updateRolePermissions'])
        ->middleware(['identity.require-permission:adminapp.roles.manage', RequireIdempotencyKey::class]);

    Route::post('/users/{user_id}/roles', [UserController::class, 'assignUserRole'])
        ->middleware(['identity.require-permission:adminapp.roles.manage', RequireIdempotencyKey::class]);

    // --- KYC (US-ADMINAPP-007/008/009) ---
    Route::get('/kyc', [KycController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.kyc.review');

    Route::get('/kyc/{kyc_submission_id}', [KycController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.kyc.review');

    Route::post('/kyc/{kyc_submission_id}/approve', [KycController::class, 'approve'])
        ->middleware(['identity.require-permission:adminapp.kyc.approve', RequireIdempotencyKey::class]);

    Route::post('/kyc/{kyc_submission_id}/reject', [KycController::class, 'reject'])
        ->middleware(['identity.require-permission:adminapp.kyc.reject', RequireIdempotencyKey::class]);

    // --- Wallets (US-ADMINAPP-010/011/012) ---
    Route::get('/wallets', [WalletController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.wallets.read');

    Route::get('/wallets/{wallet_id}', [WalletController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.wallets.read');

    Route::get('/wallets/{wallet_id}/ledger', [WalletController::class, 'ledger'])
        ->middleware('identity.require-permission:adminapp.wallets.read');

    Route::post('/wallets/{wallet_id}/freeze', [WalletController::class, 'freeze'])
        ->middleware(['identity.require-permission:adminapp.wallets.freeze', RequireIdempotencyKey::class]);

    Route::post('/wallets/{wallet_id}/unfreeze', [WalletController::class, 'unfreeze'])
        ->middleware(['identity.require-permission:adminapp.wallets.unfreeze', RequireIdempotencyKey::class]);

    // --- Transactions (US-ADMINAPP-013/014/015) ---
    Route::get('/transactions', [TransactionController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.transactions.read');

    Route::get('/transactions/{transaction_id}', [TransactionController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.transactions.investigate');

    Route::post('/transactions/{transaction_id}/reverse', [TransactionController::class, 'reverse'])
        ->middleware(['identity.require-permission:adminapp.transactions.reverse', RequireIdempotencyKey::class]);

    // --- Agents (US-ADMINAPP-016/017) ---
    Route::get('/agents', [AgentController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.agents.manage');

    Route::get('/agents/{agent_id}', [AgentController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.agents.manage');

    Route::post('/agents/{agent_id}/approve', [AgentController::class, 'approve'])
        ->middleware(['identity.require-permission:adminapp.agents.approve', RequireIdempotencyKey::class]);

    Route::patch('/agents/{agent_id}/status', [AgentController::class, 'updateStatus'])
        ->middleware(['identity.require-permission:adminapp.agents.manage', RequireIdempotencyKey::class]);

    // --- Float (US-ADMINAPP-018) ---
    Route::get('/float', [AgentController::class, 'floatIndex'])
        ->middleware('identity.require-permission:adminapp.float.manage');

    Route::post('/float/{agent_id}/adjust', [AgentController::class, 'floatAdjust'])
        ->middleware(['identity.require-permission:adminapp.float.manage', RequireIdempotencyKey::class]);

    // --- Reconciliation (US-ADMINAPP-019) ---
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])
        ->middleware('identity.require-permission:adminapp.reconciliation.read');

    Route::get('/reconciliation/{batch_id}', [ReconciliationController::class, 'show'])
        ->middleware('identity.require-permission:adminapp.reconciliation.read');

    // --- Settlement (US-ADMINAPP-020) ---
    Route::get('/settlement', [ReconciliationController::class, 'settlementIndex'])
        ->middleware('identity.require-permission:adminapp.settlement.read');

    Route::get('/settlement/{settlement_id}', [ReconciliationController::class, 'settlementShow'])
        ->middleware('identity.require-permission:adminapp.settlement.read');

    Route::post('/settlement/{settlement_id}/approve', [ReconciliationController::class, 'approveSettlement'])
        ->middleware(['identity.require-permission:adminapp.settlement.read', RequireIdempotencyKey::class]);

    // --- Compliance (US-ADMINAPP-021/022) ---
    Route::get('/audit', [ComplianceController::class, 'auditIndex'])
        ->middleware('identity.require-permission:adminapp.audit.read');

    Route::get('/reports', [ComplianceController::class, 'reportIndex'])
        ->middleware('identity.require-permission:adminapp.reports.read');

    Route::post('/reports', [ComplianceController::class, 'reportCreate'])
        ->middleware(['identity.require-permission:adminapp.reports.read', RequireIdempotencyKey::class]);

    Route::get('/reports/{report_id}', [ComplianceController::class, 'reportShow'])
        ->middleware('identity.require-permission:adminapp.reports.read');

    // --- Maker-Checker Approvals ---
    Route::post('/approvals/{approval_id}/approve', [ApprovalController::class, 'approve'])
        ->middleware([RequireIdempotencyKey::class]);

    Route::post('/approvals/{approval_id}/reject', [ApprovalController::class, 'reject'])
        ->middleware([RequireIdempotencyKey::class]);
});
