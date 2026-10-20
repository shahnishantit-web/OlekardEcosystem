<?php

/**
 * E08 Wallet Funding — AgentCashInRequest contract
 *
 * Describes the validated input shape for POST /v1/funding/agent-transactions (US-FUND-015–019).
 * Implemented as App\Modules\Funding\Infrastructure\Http\Requests\AgentCashInRequest.
 */

// ─────────────────────────────────────────────────────────────────────────────
// HTTP Request Headers
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Required headers:
 *   Authorization:   Bearer {agent_jwt_token}
 *   Idempotency-Key: {uuid_v7}         (required; 400 MISSING_IDEMPOTENCY_KEY without it)
 *   Content-Type:    application/json
 *
 * Step-up header (when amount ≥ auth.step_up_threshold BWP):
 *   X-Step-Up-Token: {step_up_token}   (from E03 Device & Session step-up flow)
 */

// ─────────────────────────────────────────────────────────────────────────────
// Request Body
// ─────────────────────────────────────────────────────────────────────────────

$request = [
    'customer_identifier' => 'string|required',          // Customer phone (E.164) or UUID v7
    'amount'              => 'integer|min:1|required',   // Cash-in amount in minor currency units
    'currency_code'       => 'string|size:3|required',   // ISO 4217; must match customer's active pocket
    'transaction_pin'     => 'string|required',          // Agent's plaintext transaction PIN (never logged)
    'correlation_id'      => 'string|uuid|required',     // Client-generated request correlation ID
];

// ─────────────────────────────────────────────────────────────────────────────
// Validation Rules Summary
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Additional validations applied by AgentCashInService:
 * - customer_identifier resolves to an active customer (404 CUSTOMER_NOT_FOUND)
 * - Customer has an active wallet pocket in currency_code (422 WALLET_NOT_FOUND)
 * - Customer KYC tier permits agent cash-in (422 KYC_INSUFFICIENT)
 * - Customer wallet is not suspended (422 WALLET_SUSPENDED)
 * - Amount within customer's per-transaction and daily limits (422 AMOUNT_OUTSIDE_LIMITS)
 * - Agent float balance ≥ amount (422 INSUFFICIENT_FLOAT)
 * - transaction_pin matches agent's hashed PIN (422 INVALID_PIN)
 * - Step-up token valid if amount ≥ threshold (403 STEP_UP_REQUIRED)
 * - fee_amount = 0 enforced at domain layer regardless of config (FR-E08-050)
 */

// ─────────────────────────────────────────────────────────────────────────────
// Success Response (201 Created)
// ─────────────────────────────────────────────────────────────────────────────

$response_201 = [
    'data' => [
        'transaction_id'          => 'uuid_v7',       // FundingTransaction ID
        'status'                  => 'completed',     // Agent cash-in completes synchronously
        'amount'                  => 5000,            // minor units (e.g., 50.00 BWP)
        'fee_amount'              => 0,               // always 0 for agent method (FR-E08-050)
        'net_amount'              => 5000,
        'currency_code'           => 'BWP',
        'transaction_reference'   => 'uuid_v7',
        'ledger_journal_id'       => 'uuid_v7',
        'receipt' => [
            'receipt_reference'   => 'uuid_v7',
            'completed_at'        => '2026-09-18T12:05:00Z',
        ],
        'customer' => [
            'masked_name'         => 'J*** D***',     // masked for privacy; not stored in events
        ],
        'created_at'              => '2026-09-18T12:05:00Z',
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// Idempotent Replay Response (200 OK)
// ─────────────────────────────────────────────────────────────────────────────

// Same as 201 body; HTTP status 200; original receipt reference returned.

// ─────────────────────────────────────────────────────────────────────────────
// Error Responses
// ─────────────────────────────────────────────────────────────────────────────

$errors = [
    400 => [
        ['error' => ['code' => 'MISSING_IDEMPOTENCY_KEY']],
        ['error' => ['code' => 'VALIDATION_ERROR', 'details' => []]],
    ],
    401 => ['error' => ['code' => 'UNAUTHENTICATED']],
    403 => [
        ['error' => ['code' => 'FORBIDDEN',         'message' => 'Missing funding.agent-cashin permission.']],
        ['error' => ['code' => 'STEP_UP_REQUIRED',  'message' => 'Enhanced authentication required for this amount.']],
    ],
    404 => ['error' => ['code' => 'CUSTOMER_NOT_FOUND']],
    422 => [
        ['error' => ['code' => 'KYC_INSUFFICIENT']],
        ['error' => ['code' => 'WALLET_NOT_FOUND']],
        ['error' => ['code' => 'WALLET_SUSPENDED']],
        ['error' => ['code' => 'AMOUNT_OUTSIDE_LIMITS',   'data' => ['min' => 100, 'max' => 500000]]],
        ['error' => ['code' => 'DAILY_LIMIT_EXCEEDED',    'data' => ['remaining' => 0]]],
        ['error' => ['code' => 'INSUFFICIENT_FLOAT',      'message' => 'Agent float balance is insufficient.']],
        ['error' => ['code' => 'INVALID_PIN',             'message' => 'Transaction PIN is incorrect.']],
    ],
];
