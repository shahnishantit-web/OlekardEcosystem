# API Response PII Masking Audit — E26 Security Hardening

**Date**: 2026-10-02
**Scope**: All API Resources returning `account_number`, `wallet_number`, `card_number`, `national_id`, `document_number`.

## Summary

**Result: PASS — All identified Resources apply masking correctly.**

## Findings

| Resource | PII Field | Masking Applied | Notes |
|----------|-----------|-----------------|-------|
| `Withdrawal\WithdrawalTransactionResource` | `account_number` (via `destination`) | `maskDestination()` → `****XXXX` | ✅ Already masked |
| `Agent\CustomerSummaryResource` | `national_id_number` | `****` + last 4 chars | ✅ Already masked — returns `masked_national_id` key |
| `Kyc\KycCaseResource` | PII (personal_info, documents) | Excluded entirely | ✅ Comment in resource confirms deliberate exclusion |
| `Wallet\WalletResource` | — | N/A | ✅ No raw PII fields exposed; `wallet_reference` is a non-sensitive reference |
| `Agent\CustomerWalletViewResource` | — | N/A | ✅ No PII fields |

## Modules with No PII Exposure (verified clean)

- Transaction (TransactionProjectionResource, TransactionReceiptResource)
- Ledger (JournalResource, LedgerAccountResource)
- Admin (UserDetailResource, WalletDetailResource, WalletSummaryResource)
- AgentFloat, Commission, Service, Qr, Notification, Risk, Reconciliation, Fees, Support, Audit

## Recommendations

- No immediate fixes required.
- Future Resources returning profile, identity, or KYC data should apply `PiiMasker::maskNationalId()` or `PiiMasker::maskPhone()` before exposing to API callers.
- The `ApiResponseMaskingTest` (T032–T033) provides regression coverage.
