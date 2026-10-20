<?php

declare(strict_types=1);

/**
 * PostEntry Request Array — E07 Wallet Ledger
 *
 * This file documents the shape of the $request array passed to
 * LedgerModuleContract::postEntry(array $request): string
 *
 * This is the internal service-to-service contract used within the Laravel monolith.
 * External REST callers use the HTTP endpoints documented in api-contract.md.
 *
 * See FR-E07-044 for the canonical requirement.
 */

/**
 * $request shape:
 *
 * [
 *   // --- Required fields ---
 *
 *   'idempotency_key' => string,
 *   // Client-supplied UUID (typically UUID v4/v7). Stored in journals.idempotency_key.
 *   // Must be unique per financial event. Replay with the same key returns the original journal ID.
 *
 *   'entry_type' => string,
 *   // One of: 'debit' | 'credit' | 'fee' | 'revenue' | 'commission'
 *   // Classification label only; the ledger does not validate which account roles appear
 *   // on which side. The posting caller is responsible for correct account selection.
 *   // 'reversal' is set automatically by LedgerReverseService; callers MUST NOT set it here.
 *
 *   'currency_code' => string,
 *   // ISO 4217 code (e.g., 'BWP', 'USD', 'USDT').
 *   // ALL lines must carry this same currency_code.
 *
 *   'correlation_id' => string,
 *   // Request correlation UUID. Propagated to journal.correlation_id and the outbox event.
 *
 *   'lines' => [
 *     // Minimum two elements: at least one debit and at least one credit.
 *     // SUM(amount WHERE direction='debit') MUST equal SUM(amount WHERE direction='credit').
 *     [
 *       'ledger_account_reference' => string,
 *       // UUID v7 pre-assigned by the consuming module (e.g., E06 pocket's ledger_account_reference).
 *       // Unknown references are auto-registered as liability/credit provisional default
 *       // (pending TBD-E07-002). Consuming modules SHOULD pre-register via registerAccount().
 *
 *       'direction' => string,
 *       // 'debit' or 'credit'
 *
 *       'amount' => int,
 *       // Positive integer; minor currency units (e.g., thebe for BWP, cents for USD).
 *       // MUST be > 0. Zero or negative values are rejected with INVALID_AMOUNT (HTTP 422).
 *     ],
 *     // ... additional lines as needed
 *   ],
 *
 *   // --- Optional fields ---
 *
 *   'transaction_reference' => string|null,
 *   // default: null
 *   // Cross-module reference to the initiating transaction (e.g., E11 transaction UUID).
 *   // Stored in journals.transaction_reference; queryable via GET /v1/ledger/journals.
 *   // Used by E11 to verify full posting via entryExists().
 *
 *   'description' => string|null,
 *   // default: null
 *   // Human-readable reason for the journal. Stored in journals.description.
 *   // MUST NOT contain PII.
 * ]
 *
 * --- Return value ---
 *
 * string $journalId
 * UUID v7 string of the created journal.
 * On idempotent replay (same idempotency_key), returns the ORIGINAL journal ID.
 * HTTP 200 is returned for replays; HTTP 201 for new journals.
 *
 * --- Validation errors (thrown before any DB write) ---
 *
 * UnbalancedJournalException  → HTTP 422 UNBALANCED_JOURNAL
 *   SUM(debit amounts) != SUM(credit amounts), OR only debits/only credits, OR zero lines.
 *
 * CurrencyMismatchException   → HTTP 422 CURRENCY_MISMATCH
 *   Any line's currency_code differs from the top-level currency_code.
 *
 * InvalidAmountException      → HTTP 422 INVALID_AMOUNT
 *   Any line amount is <= 0 or not an integer.
 *
 * AccountClosedException      → HTTP 422 ACCOUNT_CLOSED
 *   Any referenced ledger account has status = 'closed'.
 *
 * --- Example: Customer Spend (BWP 50 = 5,000 thebe) ---
 *
 * $request = [
 *   'idempotency_key'       => '01927f3a-0000-7000-0000-000000000001',
 *   'entry_type'            => 'debit',
 *   'currency_code'         => 'BWP',
 *   'transaction_reference' => '01927f3a-0000-7000-0000-000000000099',
 *   'correlation_id'        => 'corr-spend-001',
 *   'description'           => 'Customer spend at merchant',
 *   'lines' => [
 *     [
 *       'ledger_account_reference' => 'cust-pocket-ref-uuid-v7',
 *       'direction' => 'debit',
 *       'amount'    => 5000,
 *     ],
 *     [
 *       'ledger_account_reference' => 'merchant-pocket-ref-uuid-v7',
 *       'direction' => 'credit',
 *       'amount'    => 5000,
 *     ],
 *   ],
 * ];
 *
 * $journalId = $ledgerContract->postEntry($request);
 * // Returns UUID v7 string; e.g., '01928e7b-0000-7000-0000-000000000010'
 */
