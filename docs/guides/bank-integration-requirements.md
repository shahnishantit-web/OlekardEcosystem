# Bank Integration Requirements Checklist

**Status**: REQUIRED before any live bank integration (Bank 1, Bank 2, Bank 3)

All items in this checklist MUST be confirmed before enabling a bank adapter in production.
Contact the bank's technical integration team to obtain each item.

---

## 1. Base URL and Environment

- [ ] **Sandbox base URL** — Full base URL for the bank's sandbox/test environment (e.g. `https://sandbox.bank.example.com/api/v2/`)
- [ ] **Production base URL** — Full base URL for the bank's production environment
- [ ] **API versioning scheme** — Path-based (`/v1/`), header-based, or query-parameter-based?
- [ ] **IP whitelist required?** — Does the bank require server IP whitelisting? List IPs to whitelist.

---

## 2. Authentication Scheme

Choose ONE of the following and obtain the required credentials for sandbox and production separately:

- [ ] **API Key** — Header name (e.g. `X-Api-Key`), key format, key length, rotation policy
- [ ] **OAuth 2.0 (Client Credentials)** — Token endpoint URL, client_id, client_secret (store in secrets manager), token lifetime, scope
- [ ] **HMAC request signing** — Algorithm (`sha256`/`sha512`), header name(s), payload normalization (timestamp, nonce), sample test request+signature

**Sandbox credentials**: Stored in secrets manager reference `payment.bank1.sandbox.*`
**Production credentials**: Stored in secrets manager reference `payment.bank1.production.*`

> ⚠️ NEVER store raw credential values in the database, code, or logs. Store only the secrets manager reference key.

---

## 3. Webhook Signature Verification

- [ ] **Signature algorithm** — Which HMAC algorithm? (`sha256` recommended; `md5` is NOT supported)
- [ ] **Signature header name** — Which HTTP header carries the signature? (e.g. `X-Bank-Signature`, `X-Hub-Signature-256`)
- [ ] **Signature payload** — Is the signature computed over: raw body only, or raw body + timestamp + nonce?
- [ ] **Additional headers** — Are any additional headers included in the signature computation?
- [ ] **Signature format** — Hex-encoded? Base64? Prefixed with algorithm name (e.g. `sha256=...`)?
- [ ] **Callback secret rotation procedure** — Can the secret be rotated without a callback endpoint restart?
- [ ] **Sandbox webhook test tool** — Does the bank provide a tool to send test webhooks to a developer tunnel?

---

## 4. Payment Initiation

- [ ] **Checkout flow type** — Which of: `redirect`, `hosted_checkout`, `sdk`, `app_switch`?
- [ ] **Required fields** — Enumerate all required fields for payment initiation (amount, currency, reference, redirect URLs, etc.)
- [ ] **Amount units** — Integer minor units (e.g. BWP thebe) or decimal? **OleKard requires integer minor units.**
- [ ] **Redirect URL handling** — What parameter carries the transaction reference on redirect return?
- [ ] **Timeout policy** — How long does the provider allow before an unclaimed payment expires?
- [ ] **Test card numbers / test MSISDNs** — Provide test credentials for each outcome (success, failure, pending, 3DS challenge)

---

## 5. Asynchronous Confirmation

- [ ] **Real-time vs async** — Does the bank confirm payments synchronously on the redirect return, or asynchronously via webhook?
- [ ] **Status query endpoint** — Endpoint and parameters for polling payment status
- [ ] **Polling frequency limits** — Rate limits on status query endpoint
- [ ] **Final outcome SLA** — What is the maximum time before a payment reaches a terminal state (succeeded/failed)?

> ⚠️ OleKard NEVER treats redirect arrival as proof of payment. Always query the status endpoint for authoritative confirmation.

---

## 6. Supported Currencies

- [ ] **Supported currency codes** — ISO 4217 codes (e.g. BWP, ZAR, USD)
- [ ] **Minimum transaction amount** — Per currency, in integer minor units
- [ ] **Maximum transaction amount** — Per currency, in integer minor units
- [ ] **Daily limit** — Per merchant account, per currency

---

## 7. Refund API

- [ ] **Refund supported?** — Does the bank support programmatic refunds?
- [ ] **Refund endpoint** — Full URL and HTTP method
- [ ] **Required parameters** — Original transaction reference, amount, currency, refund reason code
- [ ] **Full and partial refunds** — Are both supported?
- [ ] **Refund SLA** — How long until a refund reaches terminal state?
- [ ] **Refund status query** — Can refund status be polled via API?

---

## 8. Settlement Timing

- [ ] **Settlement cycle** — T+0, T+1, T+2, or other?
- [ ] **Settlement currency** — What currency does the bank settle in?
- [ ] **Settlement report format** — CSV, MT940, ISO 20022, or custom?
- [ ] **Reconciliation reference** — Which field in the settlement report maps to OleKard's `payment_attempt.provider_reference_id`?

---

## 9. PCI Scope Confirmation

- [ ] **PCI DSS scope** — Does OleKard's integration fall within PCI DSS scope? (Redirect-only integrations are typically out of scope.)
- [ ] **Cardholder data** — Does the bank's integration require OleKard to handle raw card numbers? (If yes, escalate to security team before proceeding.)
- [ ] **SAQ type** — Which Self-Assessment Questionnaire (SAQ-A, SAQ-A-EP, SAQ-D) applies?

---

## 10. Sandbox Access Details

- [ ] **Sandbox onboarding** — Steps to obtain sandbox access and API credentials
- [ ] **Sandbox limitations** — What features are unavailable in sandbox (e.g. real-time webhooks, partial refunds)?
- [ ] **Test webhook delivery** — How to trigger test webhooks in sandbox
- [ ] **Sandbox reset policy** — Are sandbox transactions automatically cleared? How often?
- [ ] **Technical contact** — Name and email of the bank's integration support contact

---

## Pre-Live Checklist (Technical)

Before the bank adapter is enabled in production:

- [ ] All 10 sections above are complete
- [ ] `BankProviderDocumentationGap` record for this bank slot updated to `status=resolved`
- [ ] Adapter implementation class reviewed and approved by security team
- [ ] HMAC callback verification passes with production secret
- [ ] Status query endpoint tested with sandbox credentials
- [ ] Refund flow tested end-to-end in sandbox
- [ ] Circuit breaker threshold configured for this provider
- [ ] Secrets stored in secrets manager (NOT in database columns)
- [ ] Provider record created via admin UI with `is_enabled=false` initially
- [ ] Migration checklist (docs/migrations/E48-payment-module-checklist.md) step 6 complete
- [ ] Security team sign-off on PCI scope assessment

---

*This document is REQUIRED before Bank 1, Bank 2, or Bank 3 live integration.*
*Maintain a completed copy of this checklist per bank in `docs/integrations/bank-{n}-completed-checklist.md`.*
