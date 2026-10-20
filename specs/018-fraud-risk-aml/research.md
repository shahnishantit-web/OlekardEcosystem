# Research — E18 Fraud, Risk & AML

## Decision 1 — Risk Evaluation Execution Model (Sync vs. Async)

**Decision:** Hybrid evaluation model.
- **Synchronous (blocking):** Account hold check, watchlist screening, per-transaction hard limits. These run inside the transaction processing pipeline and can reject the transaction before funds move.
- **Asynchronous (queue-backed):** Velocity accumulation, unusual pattern detection, rapid fund movement, structuring detection. These run after the transaction is posted and generate alerts rather than blocking decisions.

**Rationale:** Hard limits and sanctions screening must be synchronous because reversing a completed payment to a sanctioned entity or over a hard limit is operationally and legally difficult. Pattern detection is inherently retrospective (requires historical window) and would introduce unacceptable latency into the payment flow if run synchronously.

**Alternatives considered:**
- Full synchronous: Unacceptable — velocity checks over 30-day windows cannot complete in <200ms.
- Full asynchronous: Unacceptable — would allow transactions to a sanctions-listed party to complete.

**Integration pattern:** Transaction modules (E08–E16) call `RiskEvaluatorInterface::checkPreTransaction(TransactionRiskContext)` before posting. After posting, they emit a `TransactionPostedEvent` which the Risk module's async listeners consume for pattern analysis.

---

## Decision 2 — AML Regulatory Jurisdiction

**Decision:** Primary jurisdiction is **Botswana Financial Intelligence Agency (FIA)**, governed by the Financial Intelligence Act, 2009 (as amended).

**Rationale:** The wallet ecosystem operates in Botswana (BWP currency, Botswana-based agent network). FIA is the competent AML/CFT authority. Key obligations:
- Cash Transaction Reports (CTRs) for transactions above the configurable regulatory threshold
- Suspicious Activity Reports (SARs) for identified suspicious behaviour
- Record-keeping for 5 years (customer records, transaction records)
- Risk-based customer due diligence aligned with KYC (E05)

**All monetary thresholds are configurable** via the Platform Configuration service (E28) — no hard-coded amounts. Constitution P3 prohibits hard-coded business policy values.

**Alternatives considered:**
- Multi-jurisdiction from day one: Out of scope; architecture must support future extension but initial implementation targets FIA only.
- FATF generic model: Used as the framework, but jurisdiction-specific reporting formats target FIA.

---

## Decision 3 — Risk Evaluation Latency Targets

**Decision:**
- Synchronous path (account hold + watchlist + hard limits): **<200ms p99** at peak transaction volume
- Asynchronous path (velocity, pattern): **within 5 minutes** of transaction posting

**Rationale:** 200ms is the industry standard for inline payment risk checks (Stripe, Flutterwave, M-Pesa use 100–300ms). 5 minutes gives the async engine full context from concurrent transactions before evaluating patterns.

**Implementation constraints:** Watchlist screening uses exact + normalized-fuzzy name matching against a locally managed database (not an external API call), which is achievable within the 200ms budget. External screening API integration is deferred to E28/E1000.

---

## Decision 4 — Cross-Epic Dependencies

| Dependency | What is consumed | How consumed |
|------------|-----------------|--------------|
| E03 Device & Session | Device ID, IP address, session context per request | `RiskContextResolverInterface` reads from request headers populated by E03 auth middleware |
| E04 Permissions | `risk.*` permission set | Seeded via E04 permission infrastructure |
| E05 KYC | Customer KYC tier (basic/standard/enhanced) | `KycModuleContract::getKycTierForUser(userId)` — lower KYC tier → higher base risk score |
| E06 Wallet | Wallet balance, wallet type | `WalletModuleContract::getWalletSummary(walletId)` — used for limit context |
| E08 Funding | `FundingTransactionCompleted` | Async listener for velocity accumulation |
| E09 Transfer | `TransferCompleted` | Async listener for rapid movement detection |
| E10 Withdrawal | `WithdrawalCompleted` | Async listener for withdrawal velocity |
| E12 Agent | Agent profile, agent tier | `AgentModuleContract::getAgentProfile(agentId)` — agent tier affects limit thresholds |
| E14 Commission | `CommissionPaidEvent` | Async listener for commission velocity |
| E15 Bill Payments | `ServicePaymentCompletedEvent` | Async listener for velocity |
| E16 QR Payments | `QrPaymentCompletedEvent` | Async listener for velocity and pattern |
| E28 Config | Risk thresholds, velocity windows, rule parameters | `ConfigurationServiceInterface::get('risk.*')` |

**Integration contract (outbound):** Transaction modules call `RiskEvaluatorInterface::checkPreTransaction(TransactionRiskContext): RiskEvaluationResult` synchronously before posting. Result contains `decision` (allow/block/review) and `risk_level`.

---

## Decision 5 — Edge Cases

| Edge Case | Resolution |
|-----------|-----------|
| Risk evaluation service unavailable during sync check | Default to `allow` with `LOW` risk level; emit `risk.evaluation.unavailable` audit event (via AuditServiceInterface) and ops alert. Constitution P9 — transaction reliability takes priority; risk catches up async. |
| Structuring detection window | Rolling 30-day lookback for cumulative amount analysis. 24-hour window for velocity (transaction count). Both are configurable. |
| Customer appears on watchlist after transaction completes | Retrospective watchlist scan job runs daily; generates retroactive alert with `watchlist.retrospective` type. Transaction is not reversed. |
| Concurrent case updates by two officers | Optimistic locking via `version` column on `risk_cases`. Second writer receives 409 Conflict; must refresh and re-apply. |
| Alert escalation SLA breach | Configurable SLA (default: 24 hours for HIGH alerts, 4 hours for CRITICAL). A scheduled job escalates breached alerts and notifies the risk manager. |
| SAR filing failure at FIA portal | SAR status set to `submission_failed`; draft preserved; compliance officer notified to retry. Retry limit configurable. |
| Account hold placed while transaction in-flight | In-flight transaction completes; hold applies to all subsequent transactions. Eventual consistency — not a financial integrity issue since the ledger entry is already posted. |
| Rule update while evaluation in progress | Evaluation uses rule snapshot loaded at job start (atomic read). Eventual consistency — new rule active for transactions submitted after the rule is saved and cache is cleared (within 60 seconds). |
