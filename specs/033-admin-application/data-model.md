# Data Model — E33 Admin Application

## Overview

AdminApp is a **read/aggregate layer** — it owns no database tables. All data is owned by prior epic modules (E01–E32). This document describes the **view models and DTOs** that AdminApp assembles and exposes via its API.

---

## Dashboard View Models

### OperationalDashboardMetrics
Aggregated for US-ADMINAPP-001. Cached in Redis (`adminapp:dashboard:operational`).

| Field | Type | Source Module |
|-------|------|---------------|
| `cached_at` | ISO 8601 datetime | AdminApp cache |
| `total_users` | int | E01 Identity |
| `active_users_today` | int | E01 Identity |
| `new_registrations_today` | int | E01 Identity |
| `pending_kyc_count` | int | E05 KYC |
| `approved_kyc_today` | int | E05 KYC |
| `total_transactions_today` | int | E11 Transactions |
| `transaction_volume_today_minor_units` | int | E11 Transactions |
| `transaction_currency` | string | E11 Transactions |
| `active_agents` | int | E12 Agent Management |
| `pending_agent_approvals` | int | E12 Agent Management |
| `open_disputes` | int | E22 Support & Dispute |
| `open_fraud_alerts` | int | E18 Fraud & AML |

### FinancialDashboardMetrics
Aggregated for US-ADMINAPP-002. Cached in Redis (`adminapp:dashboard:financial`).

| Field | Type | Source Module |
|-------|------|---------------|
| `cached_at` | ISO 8601 datetime | AdminApp cache |
| `total_wallet_balance_minor_units` | int | E06 Wallet Management |
| `balance_currency` | string | E06 Wallet Management |
| `total_funding_today_minor_units` | int | E08 Wallet Funding |
| `total_withdrawals_today_minor_units` | int | E10 Withdrawal |
| `total_transfers_today_minor_units` | int | E09 Transfer |
| `total_fees_collected_today_minor_units` | int | E21 Fees |
| `total_commissions_paid_today_minor_units` | int | E14 Commission |
| `total_float_outstanding_minor_units` | int | E13 Float |
| `pending_settlements_count` | int | E20 Reconciliation |
| `pending_settlements_amount_minor_units` | int | E20 Reconciliation |

### RiskDashboardMetrics
Aggregated for US-ADMINAPP-003. Cached in Redis (`adminapp:dashboard:risk`).

| Field | Type | Source Module |
|-------|------|---------------|
| `cached_at` | ISO 8601 datetime | AdminApp cache |
| `active_fraud_alerts` | int | E18 Fraud & AML |
| `high_risk_transactions_today` | int | E18 Fraud & AML |
| `aml_flags_today` | int | E18 Fraud & AML |
| `blocked_users_count` | int | E01 Identity |
| `frozen_wallets_count` | int | E06 Wallet Management |
| `failed_kyc_attempts_today` | int | E05 KYC |
| `suspicious_ip_count_today` | int | E26 Security Hardening |
| `open_support_disputes` | int | E22 Support & Dispute |
| `velocity_breach_alerts_today` | int | E18 Fraud & AML |

---

## User & Role View Models

### AdminUserSearchResult
Used by US-ADMINAPP-004/005.

| Field | Type | Notes |
|-------|------|-------|
| `user_id` | UUID v7 | |
| `full_name` | string | |
| `phone_number` | string | masked: +267 *** *** 789 |
| `email` | string\|null | |
| `account_status` | enum | `active`, `suspended`, `closed` |
| `kyc_status` | enum | `not_started`, `pending`, `approved`, `rejected` |
| `kyc_tier` | int\|null | 1, 2, 3 |
| `registered_at` | ISO 8601 | |
| `last_active_at` | ISO 8601\|null | |
| `wallet_count` | int | |

### AdminRoleEntry
Used by US-ADMINAPP-006.

| Field | Type | Notes |
|-------|------|-------|
| `role_id` | UUID v7 | |
| `role_name` | string | |
| `permission_slugs` | string[] | |
| `user_count` | int | |
| `created_at` | ISO 8601 | |

---

## KYC View Models

### KycReviewItem
Used by US-ADMINAPP-007/008/009.

| Field | Type | Notes |
|-------|------|-------|
| `kyc_submission_id` | UUID v7 | |
| `user_id` | UUID v7 | |
| `user_full_name` | string | |
| `user_phone_number` | string | |
| `requested_tier` | int | 1, 2, 3 |
| `current_tier` | int | |
| `status` | enum | `pending_review`, `approved`, `rejected` |
| `document_type` | string | e.g., `national_id`, `passport` |
| `document_front_url` | string | signed URL, 1h TTL |
| `document_back_url` | string\|null | signed URL, 1h TTL |
| `submitted_at` | ISO 8601 | |
| `reviewed_at` | ISO 8601\|null | |
| `reviewed_by` | string\|null | reviewer name |
| `rejection_reason` | string\|null | |

---

## Wallet View Models

### AdminWalletSearchResult
Used by US-ADMINAPP-010/011/012.

| Field | Type | Notes |
|-------|------|-------|
| `wallet_id` | UUID v7 | |
| `wallet_number` | string | |
| `owner_user_id` | UUID v7 | |
| `owner_name` | string | |
| `wallet_type` | enum | `customer`, `agent_float`, `system` |
| `status` | enum | `active`, `frozen`, `closed` |
| `balance_minor_units` | int | |
| `currency_code` | string | ISO 4217 |
| `created_at` | ISO 8601 | |
| `frozen_at` | ISO 8601\|null | |
| `frozen_by` | string\|null | admin name |
| `freeze_reason` | string\|null | |

---

## Transaction View Models

### AdminTransactionSearchResult
Used by US-ADMINAPP-013/014/015.

| Field | Type | Notes |
|-------|------|-------|
| `transaction_id` | UUID v7 | |
| `reference_number` | string | human-readable |
| `type` | enum | `wallet_transfer`, `agent_cash_in`, `agent_cash_out`, `funding`, `withdrawal`, `bill_payment` |
| `status` | enum | `pending`, `completed`, `failed`, `reversed` |
| `amount_minor_units` | int | |
| `currency_code` | string | |
| `fee_minor_units` | int | |
| `initiator_user_id` | UUID v7 | |
| `initiator_name` | string | |
| `source_wallet_id` | UUID v7\|null | |
| `destination_wallet_id` | UUID v7\|null | |
| `created_at` | ISO 8601 | |
| `completed_at` | ISO 8601\|null | |
| `reversal_eligible` | bool | true for `wallet_transfer`, `agent_cash_in`, `agent_cash_out` |
| `reversal_id` | UUID v7\|null | set if reversed |
| `fraud_flags` | string[] | |
| `idempotency_key` | string | |

---

## Agent View Models

### AdminAgentProfile
Used by US-ADMINAPP-016/017/018.

| Field | Type | Notes |
|-------|------|-------|
| `agent_id` | UUID v7 | |
| `user_id` | UUID v7 | |
| `full_name` | string | |
| `phone_number` | string | |
| `business_name` | string\|null | |
| `agent_status` | enum | `pending_approval`, `active`, `suspended`, `terminated` |
| `approved_at` | ISO 8601\|null | |
| `approved_by` | string\|null | |
| `float_balance_minor_units` | int | |
| `float_currency` | string | |
| `float_limit_minor_units` | int | |
| `commission_rate` | decimal | e.g., 0.015 |
| `total_cash_in_today_minor_units` | int | |
| `total_cash_out_today_minor_units` | int | |
| `location` | string\|null | |

---

## Finance View Models

### ReconciliationSummary
Used by US-ADMINAPP-019.

| Field | Type | Notes |
|-------|------|-------|
| `batch_id` | UUID v7 | |
| `period_start` | ISO 8601 date | |
| `period_end` | ISO 8601 date | |
| `status` | enum | `pending`, `in_progress`, `completed`, `failed` |
| `total_transactions` | int | |
| `matched_count` | int | |
| `unmatched_count` | int | |
| `discrepancy_amount_minor_units` | int | |
| `currency_code` | string | |
| `run_at` | ISO 8601 | |
| `completed_at` | ISO 8601\|null | |

### SettlementSummary
Used by US-ADMINAPP-020.

| Field | Type | Notes |
|-------|------|-------|
| `settlement_id` | UUID v7 | |
| `settlement_date` | ISO 8601 date | |
| `status` | enum | `pending`, `processing`, `completed`, `failed` |
| `total_amount_minor_units` | int | |
| `currency_code` | string | |
| `net_position_minor_units` | int | positive = owed to system |
| `provider` | string | settlement bank/provider |
| `reference` | string\|null | bank reference |

---

## Compliance View Models

### AuditEventEntry
Used by US-ADMINAPP-021.

| Field | Type | Notes |
|-------|------|-------|
| `event_id` | UUID v7 | |
| `event_type` | string | e.g., `WALLET_FROZEN`, `KYC_APPROVED` |
| `actor_id` | UUID v7 | admin user who performed the action |
| `actor_name` | string | |
| `subject_id` | UUID v7\|null | affected entity |
| `subject_type` | string\|null | `user`, `wallet`, `transaction`, `agent` |
| `description` | string | human-readable summary |
| `metadata` | object | action-specific context |
| `occurred_at` | ISO 8601 | |
| `correlation_id` | UUID | |

### ReportEntry
Used by US-ADMINAPP-022.

| Field | Type | Notes |
|-------|------|-------|
| `report_id` | UUID v7 | |
| `report_type` | string | e.g., `transaction_summary`, `kyc_volume`, `agent_performance` |
| `status` | enum | `pending`, `generating`, `ready`, `failed` |
| `period_start` | ISO 8601 date | |
| `period_end` | ISO 8601 date | |
| `requested_by` | string | |
| `requested_at` | ISO 8601 | |
| `ready_at` | ISO 8601\|null | |
| `download_url` | string\|null | signed URL, 1h TTL |
| `format` | enum | `csv`, `pdf`, `xlsx` |

---

## Maker-Checker Action Record

### PendingAdminAction
Created when a maker initiates a maker-checker story (008/009/011/012/015/016). Owned by E28 `MakerCheckerWorkflow`; AdminApp reads status.

| Field | Type | Notes |
|-------|------|-------|
| `approval_id` | UUID v7 | |
| `action_type` | string | e.g., `adminapp.kyc.approve` |
| `action_payload` | JSON | entity ID + parameters for the action |
| `status` | enum | `pending_approval`, `approved`, `rejected`, `executed`, `expired` |
| `maker_id` | UUID v7 | admin who submitted |
| `checker_id` | UUID v7\|null | admin who reviewed |
| `created_at` | ISO 8601 | |
| `decided_at` | ISO 8601\|null | |
| `executed_at` | ISO 8601\|null | |
| `expires_at` | ISO 8601 | 48 hours after creation |
| `rejection_note` | string\|null | |
