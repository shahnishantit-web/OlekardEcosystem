<?php

/**
 * E08 Wallet Funding — InitiateFundingRequest contract
 *
 * Describes the validated input shape for POST /v1/funding/transactions (US-FUND-001–003).
 * Implemented as App\Modules\Funding\Infrastructure\Http\Requests\InitiateFundingRequest.
 */

// ─────────────────────────────────────────────────────────────────────────────
// HTTP Request Headers
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Required headers:
 *   Authorization:   Bearer {jwt_token}
 *   Idempotency-Key: {uuid_v7}         (required; 400 MISSING_IDEMPOTENCY_KEY without it)
 *   Content-Type:    application/json
 */

// ─────────────────────────────────────────────────────────────────────────────
// Request Body
// ─────────────────────────────────────────────────────────────────────────────

$request = [
    'wallet_id'      => 'string|uuid|required',       // Target wallet UUID v7
    'currency_code'  => 'string|size:3|required',     // ISO 4217; must match wallet pocket
    'amount'         => 'integer|min:1|required',     // Gross amount in minor currency units
    'funding_method' => 'string|required|in:mobile_money,bank_transfer,card',
                                                       // Agent method only via POST /v1/funding/agent-transactions
    'correlation_id' => 'string|uuid|required',       // Client-generated request correlation ID
];

// ─────────────────────────────────────────────────────────────────────────────
// Validation Rules Summary
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Additional validations applied by FundingInitiationService (beyond FormRequest):
 * - Wallet must belong to the authenticated customer (IDOR: 404 WALLET_NOT_FOUND if not)
 * - currency_code must match an active pocket on the wallet
 * - Customer KYC tier must permit the selected funding_method (422 KYC_INSUFFICIENT)
 * - Amount validated against limits (422 AMOUNT_BELOW_MINIMUM / AMOUNT_ABOVE_MAXIMUM)
 */

// ─────────────────────────────────────────────────────────────────────────────
// Success Response (201 Created)
// ─────────────────────────────────────────────────────────────────────────────

$response_201 = [
    'data' => [
        'id'                      => 'uuid_v7',          // FundingTransaction ID
        'status'                  => 'pending',
        'wallet_id'               => 'uuid_v7',
        'currency_code'           => 'BWP',
        'amount'                  => 10000,              // minor units (e.g., 100.00 BWP)
        'fee_amount'              => 50,                 // calculated from config rate
        'net_amount'              => 9950,               // amount - fee_amount
        'funding_method'          => 'mobile_money',
        'transaction_reference'   => 'uuid_v7',
        'bank_reference_code'     => null,               // null until confirmed (bank transfer)
        'bank_reference_expires_at' => null,
        'limits'                  => [                   // current limits for this method + tier
            'min_amount'  => 100,
            'max_amount'  => 500000,
            'daily_max'   => 2000000,
            'daily_used'  => 15000,
            'daily_remaining' => 1985000,
        ],
        'created_at'              => '2026-09-18T12:00:00Z',
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// Idempotent Replay Response (200 OK)
// ─────────────────────────────────────────────────────────────────────────────

// Identical to 201 body but HTTP status 200; transaction may be in any status.

// ─────────────────────────────────────────────────────────────────────────────
// Error Responses
// ─────────────────────────────────────────────────────────────────────────────

$errors = [
    400 => [
        'error' => ['code' => 'MISSING_IDEMPOTENCY_KEY',   'message' => 'Idempotency-Key header is required.'],
        'error' => ['code' => 'VALIDATION_ERROR',          'message' => 'Request validation failed.', 'details' => []],
    ],
    401 => ['error' => ['code' => 'UNAUTHENTICATED']],
    403 => ['error' => ['code' => 'FORBIDDEN',             'message' => 'Missing funding.initiate permission.']],
    404 => ['error' => ['code' => 'WALLET_NOT_FOUND',      'message' => 'Wallet not found or does not belong to you.']],
    422 => [
        ['error' => ['code' => 'KYC_INSUFFICIENT',         'message' => 'Your KYC level does not permit this funding method.']],
        ['error' => ['code' => 'AMOUNT_BELOW_MINIMUM',     'message' => 'Amount is below the minimum.', 'data' => ['min' => 100]]],
        ['error' => ['code' => 'AMOUNT_ABOVE_MAXIMUM',     'message' => 'Amount exceeds the maximum.', 'data' => ['max' => 500000]]],
        ['error' => ['code' => 'DAILY_LIMIT_EXCEEDED',     'message' => 'Daily funding limit reached.', 'data' => ['remaining' => 0]]],
        ['error' => ['code' => 'UNSUPPORTED_FUNDING_METHOD']],
        ['error' => ['code' => 'FUNDING_METHOD_UNAVAILABLE']],
    ],
];
