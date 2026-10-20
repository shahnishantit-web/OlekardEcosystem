# Client Architecture Response — Impact Analysis

**Source document:** OKI-ARCH-2026-003, "Strategic Architecture & Third-Party Integration Framework," v1.0, 25 August 2026, OleKard International (Pty) Ltd.
**Analysis date:** 2026-08-26
**Status:** Analysis only. No specs, tasks, or code were modified in this pass.
**Scope:** E999 Platform Foundation (complete), E01 Central Identity & Authentication (in progress), and the 538-story backlog scaffolding (E02–E34).

---

## 1. Executive Summary

OKI-ARCH-2026-003 is now the top of the source-of-truth hierarchy. It does not require rework of anything already built. The two converged artifacts — **E999 Platform Foundation** (154/154 tasks) and the completed portion of **E01 Central Identity & Authentication** — are largely **compatible by construction** with the client's principles (provider neutrality, no vendor lock-in, TBD-not-invented, schema-per-module isolation, externalized secrets). Nothing in either artifact asserts that OleKard is a bank, holds customer funds, or has selected a regulated partner, bank, MNO, KYC/AML/SMS vendor, or cloud geography.

Three real gaps exist, none of which block continued E01 work:

1. **No provider-adapter seam exists anywhere in Platform Foundation.** The client's adapter-based integration model (banks, MNOs, KYC, AML, SMS, payment gateways, utilities) has zero supporting contracts today — not even a generic `ProviderAdapterInterface`. This will block E08/E10/E12/E15/E20/E25 planning, not E01.
2. **OTP/SMS has a provider-neutral abstraction (`OtpDeliveryContract`) but no primary/fallback/alternate-channel routing**, which the client explicitly requires (Section 9). This is an E01-adjacent gap: the seam exists, the routing logic does not.
3. **Retention, cloud-neutrality, and credential-ownership governance are under-specified** relative to the client's requirements — present as single flat config items or entirely absent, not as violations but as missing seams to add before KYC/AML/financial data actually lands in the system.

**Authentication biometrics vs. KYC biometrics are already correctly separated** in both spec and code — this was the highest-priority check in this pass and it passes cleanly. TBD-004 (biometric SDK abstraction) should remain open; see §8 for the classification evidence and recommendation.

**Recommendation: Continue E01 implementation unchanged.** Route the provider-adapter and OTP-fallback gaps into a scoped Platform Foundation extension (ADR-required) before E08/E10/E12/E15/E20/E25 planning begins. No pause is warranted. Full detail and evidence in §4–§23.

---

## 2. Source-of-Truth Hierarchy

Applied per the client's instruction:

1. **OKI-ARCH-2026-003** (25 Aug 2026) — this document, now authoritative for architecture/security/compliance/vendor-selection.
2. Approved 538-story SRS / master roadmap (`specs/000-master-roadmap/spec.md`).
3. Constitution (`.specify/memory/constitution.md`, v1.0.0).
4. Platform Foundation spec (`specs/999-platform-foundation/`) — converged, 154/154 tasks.
5. E01 spec (`specs/001-central-identity-authentication/`) — converged for phases 1–21; phases 22–23 open.
6. Plans (`plan.md` per feature).
7. Tasks (`tasks.md` per feature).
8. Current implementation (`wallet-ecosystem/services/api`).
9. Tests.

No conflict was found between OKI-ARCH-2026-003 and the Constitution or the master roadmap — the constitution's TBD-governance (Principle 2), configuration-over-hardcoding (Principle 3), and domain-boundary (Principle 7) principles already anticipate the client's no-assumption/adapter requirements. The constitution needs no amendment. Where a lower artifact is silent (not contradictory) on a client requirement, this is logged as a **gap**, not a **conflict** — per the rule, lower artifacts cannot override the client document, but a lower artifact that simply hasn't addressed a topic yet isn't overriding anything either.

---

## 3. Client Decision Classification

Classifications below reproduce the client document's own governance framework (its Section 16) applied to each numbered decision area. Where the client document itself hedges (e.g., "preferred," "should," no "CONFIRMED" banner), this is preserved rather than upgraded.

| Area | Client's own banner/language | Classification |
|---|---|---|
| Regulatory/operating model (§3) | "CRITICAL — CONFIRMED CLIENT DECISION" | **A** |
| Mobile money / MNO strategy (§4) | "CRITICAL — CONFIRMED CLIENT DECISION" | **A** |
| Banking multi-bank strategy (§5) | "CRITICAL — CONFIRMED CLIENT DECISION" | **A** |
| KYC orchestration requirement (§6) | "CRITICAL — CONFIRMED CLIENT DECISION" | **A** |
| AML capability requirement (§6) | "CRITICAL — CONFIRMED CLIENT DECISION" | **A** |
| KYC/AML *provider identity* | "No KYC/AML provider... permanently selected... TBD" | **D** |
| Fraud/risk capability (§7) | "HIGH PRIORITY — REQUIRED" (not "CONFIRMED") | **B**-leaning (treated as a Phase 1 requirement, not yet a regulatory citation) |
| Auth-vs-KYC biometric separation rule (§8) | "CRITICAL CLARIFICATION" | **A** (the separation itself is mandatory) |
| Passkeys/WebAuthn as *specific technology* (§8A) | "OleKard's **preferred** approach" | **C** — see §8 for full reasoning; not upgraded to A |
| OTP/SMS provider-neutral, primary/fallback/alt-channel (§9) | "CRITICAL — PROVIDER-NEUTRAL"; provider "TBD" | **A** for the architecture pattern, **D** for the provider |
| Settlement/reconciliation dual-authority + no-silent-resolution (§10) | "CRITICAL" / "CRITICAL PRINCIPLE" | **A** |
| Direct Top-Up / utility integration model (§11) | No explicit banner; principle language | **C** (architecture pattern recommended, no regulatory citation given) |
| Utility protocol (STS/DLMS-COSEM) | "must be confirmed against actual target utility environment" | **D** |
| OleKard Connect communications layer (§12) | No banner | **E** (roadmap capability; ledger-independence rule is **A**) |
| Live AI translation (§12) | Explicit non-alteration rule for transaction data | **A** for the non-alteration rule, **E** for the feature itself |
| ODSA™ satellite access (§12) | "FUTURE CAPABILITY — NOT A PHASE 1 DEPENDENCY" | **E** |
| Data governance/privacy-by-design (§13) | "CRITICAL" | **A** |
| Data residency (cloud-neutral, Botswana-aligned) (§13) | "should prioritise... must not prematurely lock" | **A** for the neutrality constraint, **D** for final geography |
| Retention (purpose/regulatory/contractual) (§13) | No banner, prescriptive language | **A** for the principle, **D** for actual periods |
| Production account ownership (§14) | No banner, prescriptive language | **A** |
| Rail-agnostic architecture (§15) | "ONE OF THE MOST IMPORTANT ARCHITECTURAL DECISIONS" | **A** |
| Decision governance framework itself (§16) | Procedural | **A** (adopted as-is for this analysis) |

---

## 4. Platform Foundation Impact Matrix

| Client Decision | Existing Foundation Capability | Gap | Severity | Required Change | Artifact | Implementation Impact |
|---|---|---|---|---|---|---|
| Regulated-partner model; OleKard must not assume it holds customer funds (§3) | `WalletModuleContract`/`LedgerModuleContract` are custody-silent — no field asserts OleKard as fund holder (module-contracts.md:289-325) | Neither violates nor satisfies the requirement; the concept of an external custodian reference doesn't exist yet | Low (nothing built yet) | Add custodian/settlement-partner reference field to the eventual `LedgerEntryRequest`/Wallet schema when E06/E07 are specced | `specs/999-platform-foundation/contracts/module-contracts.md`, future `specs/006-wallet-management`, `specs/007-wallet-ledger` | Foundation contract addition (additive, non-breaking) — do before E06/E07 planning |
| Provider-neutral adapter architecture for banks/MNO/KYC/AML/SMS/payment gateways (§4,5,6,9) | **None.** No `ProviderAdapterInterface`, `ExternalReferenceMapping`, webhook contract, health/routing/failover contract anywhere in Foundation. Only generic `PROVIDER_ERROR`/`PROVIDER_TIMEOUT` HTTP codes exist (api-response-envelope.md:121-122) | Full gap — this is the single largest architectural gap identified in this pass | **High** (blocks E08/E10/E12/E15/E20/E25 planning, not E01) | Define generic provider-adapter contracts once in Foundation (or explicitly delegate to E25) — see §6 and §15 | New Foundation FRs + contracts, or `specs/025-api-integration-platform/` | **P1** — needs an ADR before any epic downstream of Foundation begins detailed planning |
| No unmatched transaction may be silently resolved; every exception carries status/reason/owner/timestamp (§10) | Idempotency + outbox pattern gives durable, atomic, replayable event delivery (idempotency-protocol.md:82-94; event-envelope.md:153-194) but no reconciliation/exception-queue contract exists | Reusable primitives exist; the reconciliation-specific contract does not | Medium | Define `ProviderReconciliationContract` with matched/unmatched/pending/failed states before E20 planning | Future `specs/020-reconciliation-settlement/` + possible Foundation extension | P1/P2 |
| Data residency neutrality; no premature cloud-geography lock-in (§13) | No live cloud-provider coupling. `SecretProvider`/`EnvSecretProvider` abstraction with "Future: VaultSecretProvider, AwsSecretsManagerProvider" (plan.md:702-704); no region strings in specs, contracts, or `.env.example` | Compliant by omission; the neutrality is implicit, not an explicit stated requirement in Foundation | Low | Optionally add an explicit "cloud-provider-neutral" FR to Foundation for documentation completeness | `specs/999-platform-foundation/spec.md` | P3 (documentation only) |
| Cloud/vendor hygiene | Dead AWS defaults sit unused in Laravel boilerplate (`config/cache.php:83`, `config/queue.php:59,62`, `config/services.php:24`) under inactive drivers (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`) | Not a live violation, but a copy-paste risk if a production `.env` accidentally activates the SQS/DynamoDB blocks | Low | Remove or clearly comment the unused AWS blocks | `wallet-ecosystem/services/api/config/{cache,queue,services}.php` | P3 (cleanup) |
| Purpose-based, regulatory, contractual retention, not indefinite, differentiated by data category (§13) | Single flat FR-068 line item: "...retention periods..." in a list of configurable policy categories (spec.md:694); no per-category scheme; `auth.session.retention_days` (default 90) is the only concrete precedent (`ConfigurationSecurityService.php:232-235`) | No general purpose-keyed retention model exists | Medium | Generalize the existing config-key pattern into a retention registry (e.g., `retention.kyc_records_days`, `retention.financial_records_days`, `retention.aml_records_days`, `retention.audit_logs_days`, `retention.communications_days`) as each module lands data | Foundation `ConfigurationServiceInterface` extension + per-module adoption | P1 (seam) / P2 (per-module rollout as each epic builds) |
| Production accounts owned by OleKard/regulated entity, not individual developers (§14) | Mechanism for secret externalization exists (`SecretProviderInterface`); **no ownership/governance metadata concept exists at all** — zero references to account ownership anywhere in specs or code | Full gap, but currently low-consequence since no real provider accounts exist yet (all providers are TBD/stub) | Low today, will become High once real vendor accounts are provisioned | Add a lightweight credential-ownership registry/governance policy (who is the account holder of record) before any real provider account is created | New governance doc, likely paired with an ADR; not a code contract | **P3** now, escalates to **P0** the moment a real KYC/AML/SMS/bank/MNO account is provisioned |
| Schema separation between identity/KYC/financial/AML/audit/communications data (§13) | Nine schemas already exist, one per module (`platform, identity, wallet, ledger, kyc, transaction, risk, agent, notification`); cross-schema JOINs explicitly forbidden and enforced by Pest architecture tests + Deptrac CI (data-model.md:245; module-contracts.md:21,367; ADR-003) | None — this fully satisfies the requirement already | — | None needed | `specs/999-platform-foundation/`, ADR-003 | Already converged |
| Settlement dual-authority language: OleKard ledger authoritative for platform state, external institution authoritative for its own settlement record (§10) | Absent. Only "PostgreSQL is authoritative over Redis" language exists (idempotency context), which is a different authority statement | Full gap | Medium | Add explicit dual-authority principle to Foundation or the future E20 spec | `specs/999-platform-foundation/spec.md` or `specs/020-reconciliation-settlement/spec.md` | P2 |

---

## 5. E01 Impact Matrix

| Client Decision | E01 Area | Current State | Gap | Severity | Required Change | Artifact | Phase |
|---|---|---|---|---|---|---|---|
| Auth biometrics ≠ KYC biometrics, must not be conflated (§8) | `BiometricAssertionContract`, `BiometricController`, `CredentialType` | Fully separated — device-native, public-key challenge/response; DTOs carry no biometric image/template fields; zero KYC-style (selfie/liveness/face-match) code anywhere in E01 or the `kyc` module (empty stub) | None | — | None | `app/Modules/Identity/Domain/Contracts/BiometricAssertionContract.php`, `contracts/module-contracts.md:132-197` | Converged |
| Device-native biometrics / Passkeys / WebAuthn *preferred* (§8A) | `BiometricAssertionContract` (customer/agent) vs. `web-auth/webauthn-framework` (Admin MFA only, FIDO2/WebAuthn, BR-028/FR-E01-049) | Admin MFA already uses literal WebAuthn/FIDO2. Customer/Agent primary biometric login uses a **custom** `BiometricAssertionContract` that is semantically WebAuthn/passkey-shaped (signed device assertion, biometric never transits network) but is not the literal WebAuthn protocol/library | Partial — functional intent matches, literal protocol choice for Customer/Agent remains open (TBD-004) | Low (already spirit-compliant) | See §8 recommendation: keep TBD-004 open, but weight future SDK selection toward standards-based WebAuthn/passkey libraries | `spec.md:1145` (TBD-004), `IdentityServiceProvider.php:65-67` | Deferred by design — do not resolve now |
| OTP/SMS provider-neutral, primary→fallback→alternate-channel (§9) | `OtpDeliveryContract`, `OtpService`, `StubOtpDeliveryProvider` | Abstract single-provider interface exists (`auth.otp.provider` config, TBD-001); `OtpService::resolveChannelType()` calls `deliver()` **once**, no retry, no secondary provider, no alternate-channel fallback | Real gap — the routing pattern the client requires (primary → fallback → alternative channel) is architecturally absent | Medium | Add a routing layer above `OtpDeliveryContract` (ordered provider chain + channel fallback) as a Foundation-shared or E01-owned addition | `app/Modules/Identity/Domain/Services/OtpService.php`, `OtpDeliveryContract.php` | New — not yet in tasks.md; needs a plan amendment before implementation |
| No AI/system may independently freeze/confiscate funds (§6, compliance decision authority) | Step-up (`StepUpService`), MFA, session revocation (`Identity.Account.Locked`) | E01 only locks accounts/sessions (auth-layer control), never touches funds — no financial-freeze capability exists in E01 at all | None (out of E01's scope by construction) | — | None | — | Converged |
| KYC/AML business logic must sit outside identity/auth (§6) | Out-of-scope declarations (spec.md:41,62), `Identity.User.Registered` event → E05 consumer (spec.md:660,902) | Clean boundary — zero KYC logic in E01; handoff is event-based, not a synchronous call | None | — | None | `IdentityModuleContract` (module-contracts.md:11-77) | Converged |
| Fraud/risk signal availability for future E18 (§7) | Events: `Identity.Session.LoginFailed` → E18, `Identity.Device.NewDeviceDetected` → E17/E26 (spec.md:664-666) | Signal-shaped events already exist and are consumed conceptually by E18/E26; no risk-scoring logic exists in E01 (correctly, since that's E18's job) | Partial — events exist, but no device/IP-intelligence payload richness has been evaluated against E18's eventual needs | Low | Revisit event payload richness once E18 is actually specced (not now) | `spec.md` §15 event table | Domain-future (E18) |
| Production account/secrets governance (§14) | `SecretProviderInterface`/`EnvSecretProvider`, `IdentityServiceProvider.php:80-81` | Mechanism exists; ownership metadata concept does not (same Foundation-level gap, §4) | See Foundation matrix | Low today | None at E01 level — inherits Foundation gap | — | Inherits Foundation P3/P0 |
| Retention (§13) | `SessionCleanupJob` (configurable, 90-day default) vs. `OtpChallengeCleanupJob` (hard-coded 24h, not sourced from config) | Inconsistent: one retention control is configurable, the other is hard-coded | Low-medium | Migrate `OtpChallengeCleanupJob`'s cutoff to a configuration key (`auth.otp.challenge_retention_hours`) for consistency with the session pattern and the client's "configurable, purpose-based" requirement | `app/Modules/Identity/Infrastructure/Jobs/OtpChallengeCleanupJob.php:30` | Small, low-risk — candidate for Polish phase (P23) or a converge pass |
| E01 completion state (not a client-driven gap, but material to sequencing) | tasks.md Phases 22–23 | E2E scenario tasks (T202–T207, 0/6) and Polish/Documentation tasks (T208–T214, 0/7, including OpenAPI generation and ADR-006/007) are unchecked; T215 PIN-concurrency test unchecked | Incomplete convergence | Medium | Finish P22/P23 before declaring E01 fully converged — independent of this client document | `specs/001-central-identity-authentication/tasks.md:414-434` | Existing backlog, unrelated to OKI-ARCH-2026-003 |

---

## 6. Integration Adapter Gap Analysis

The client requires eight adapter/orchestration categories (Mobile Money, Banking, KYC, AML, Fraud/Risk, OTP/SMS, Utility, Payment Gateway/Settlement). Checked against everything that exists today:

- **Nothing implements any of the eight.** This is expected — per the master roadmap, all eight belong to epics sequenced after E01 (E08 Funding, E10 Withdrawal, E12 Agent/E13 Float for MNO, E05 KYC, E18 AML/Fraud, E15 Utility, E20 Settlement, E25 API Integration Platform).
- **The scaffolding already anticipates this correctly.** Every one of the ten sampled future-epic `spec.md` files (§4 boilerplate) already carries the line *"Provider selection remains TBD"* — the No-Assumption Rule is already baked into the backlog template, not something that needs to be retrofitted.
- **E25 (API & Integration Platform) already has the right shape of stories**, even though they're currently generic boilerplate: `US-API-008 Integrate OTP provider`, `US-API-009 Integrate SMS provider`, `US-API-011 Integrate KYC provider`, `US-API-012 Integrate payment provider`, `US-API-015 Receive provider webhook`, `US-API-016 Validate webhook signature`, `US-API-017 Implement webhook idempotency`. This is the natural home for `ProviderWebhookContract` and webhook-idempotency reuse of the existing outbox/idempotency pattern.
- **The open architectural question is *where* the generic adapter contracts (`ProviderAdapterInterface`, `ProviderHealthInterface`, `ProviderRoutingInterface`, `ProviderFailoverInterface`, `ExternalReferenceMapping`) should be defined**: once in Platform Foundation (shared by all downstream epics — MNO, banking, KYC, AML, SMS, utility, payment gateway all need the same shape), or independently inside E25 and referenced by others. Defining it once in Foundation avoids seven epics inventing seven divergent adapter patterns, but it means reopening a converged (154/154) spec.
  - **This is a genuine architecture decision, not decided in this pass.** It is logged in §19 as an ADR requirement.

No provider contract, health check, routing table, or failover logic was invented in this analysis, per instruction.

---

## 7. Identity vs. KYC Boundary Analysis

This boundary is **clean and already correctly drawn**:

- E01 explicitly lists KYC as out of scope (spec.md:41,62): *"Out of scope for E01: all wallet, ledger, KYC, transaction, risk..."* and *"Non-Goal: Implement KYC verification workflows or biographic/document checks."*
- The only connective tissue is event-based, not a synchronous dependency: `Identity.User.Registered` is emitted by E01 and consumed by E05 "(KYC trigger)" (spec.md:660,902).
- `IdentityModuleContract` (module-contracts.md:11-77) exposes only `resolveActorFromToken()`, `resolveServiceActor()`, `verifyStepUpScope()`, `revokeSession()` — nothing KYC-shaped leaks across the boundary, and direct queries into `identity.*` tables from other modules are explicitly prohibited.
- The `kyc` Postgres schema already exists (config/database.php) but is empty — `KycServiceProvider.php` is a bare stub with a comment deferring all business logic to E05 (labeled "E03" in one stub comment — worth a minor correction, see §22).
- **What E05 will need from E01 when it is eventually specced** (not to be built now): actor identity resolution (already available via `IdentityModuleContract::resolveActorFromToken()`), the `Identity.User.Registered` event (already emitted), and a verification-status event flowing the other way (E05 → E01/other consumers) — this reverse event does not exist yet and should be defined when E05 is planned, not now.

No KYC business logic, tier model, or vendor reference should be added to E01. None currently exists. Compliant as-is.

---

## 8. Authentication Biometrics / Passkeys Analysis

**Finding: the separation the client demands is already fully implemented and is not ambiguous anywhere in spec, plan, contracts, or code.**

Evidence:
- `BiometricAssertionContract` (`app/Modules/Identity/Domain/Contracts/BiometricAssertionContract.php:15-34`) is a device-native, public-key challenge/response interface — `verify(challenge, assertion, registeredPublicKey)`. Its DTOs (`BiometricEnrollmentResult`, `BiometricVerificationResult`) carry only `devicePublicKey`/`deviceIdentifier`/`verified`/`failureReason` — never an image, template, or raw biometric.
- `BR-009` (spec.md:140) states explicitly: *"actual biometric data NEVER leaves the user's device. The server validates a signed assertion provided by the device SDK."*
- `contracts/module-contracts.md:132-197` repeats the constraint verbatim: *"Raw biometric templates MUST NOT be passed to this interface... Biometric data never transits the network."*
- Zero KYC-style biometric language (selfie/liveness/face-match/document-match) exists anywhere in E01's spec, plan, tasks, or contracts, or in the `Identity` module's code. A repo-wide grep for "liveness"/"selfie"/"face-match" returns no hits.

**On the specific technology (Passkeys/WebAuthn) — do not silently reclassify:**

The client document's Section 8 heading is *"CRITICAL CLARIFICATION,"* not *"CONFIRMED CLIENT DECISION"* (contrast with Sections 3, 4, 5, 6, which explicitly carry the "CONFIRMED CLIENT DECISION" banner). The operative sentence is: *"OleKard's preferred approach is device-native biometric authentication via Passkeys / WebAuthn"* — "preferred," not "MUST" or "required." The client's own Section 17 decision matrix lists it as *"Device-native biometrics / Passkeys / WebAuthn preferred,"* again using "preferred" rather than confirming a specific SDK/library.

Separately, `web-auth/webauthn-framework` (literal WebAuthn) is **already in use today**, but only for **Admin MFA/step-up** (BR-028, FR-E01-049) — a different actor class and a different purpose (second factor, not primary biometric login) than the client's Section 8A description (which is framed around customer-style device authentication generally).

**Recommendation (not applied — evidence provided for client/architecture sign-off):**
- The mandatory *separation* rule (auth biometric ≠ KYC biometric) is already **A — Confirmed** and E01 already complies.
- The specific *technology preference* (Passkeys/WebAuthn for Customer/Agent primary login) should be classified **C — Technical Recommendation**, not upgraded to A. The client's own wording ("preferred") and the absence of a "CONFIRMED CLIENT DECISION" banner on that specific sentence do not meet the bar the client set for itself elsewhere in the same document.
- **TBD-004 (spec.md:1145, "Biometric assertion protocol details (platform-specific SDK)") should remain OPEN (classification D — Pending Decision).** It should not be resolved to a specific SDK now. However, when it is eventually resolved, this analysis recommends weighting the SDK choice toward a standards-based WebAuthn/passkey implementation over a proprietary SDK, since that satisfies the client's stated preference without requiring an architecture rewrite (the existing `BiometricAssertionContract` challenge/response shape is already compatible with a WebAuthn-backed concrete implementation).

No vendor, SDK, or library was selected in this pass.

---

## 9. OTP/SMS Provider-Neutral Architecture Analysis

**Partial compliance — the abstraction exists, the routing does not.**

What exists:
- `OtpDeliveryContract::deliver(destination, otp, channelType, correlationId): OtpDeliveryResult` (module-contracts.md:82-129) — genuinely vendor-neutral; no vendor name appears anywhere in the contract.
- `auth.otp.provider` is a runtime configuration key (currently the placeholder value `"configured"`), not a hard-coded vendor (spec.md:267; FR-E01-052, spec.md:218).
- `StubOtpDeliveryProvider` is the only concrete binding today, and it logs at DEBUG level only — explicitly marked "MUST NOT be used in production."
- TBD-001 (OTP provider selection) is correctly still open.

What is missing, against the client's explicit architecture (§9):
```
PRIMARY PROVIDER
  ↓
FALLBACK PROVIDER
  ↓
ALTERNATIVE COMMUNICATION CHANNEL
```
`OtpService::resolveChannelType()` and the delivery call path invoke `deliver()` exactly once, with a single bound implementation. There is no concept of an ordered provider chain, no retry-to-secondary-provider logic, and no automatic channel fallback (e.g., SMS fails → fall back to email) anywhere in the codebase or the spec.

This gap also applies verbatim to the wider notification surface the client lists in §9 (registration OTP, login verification, device verification, PIN reset, step-up, high-risk transaction confirmation, transaction notifications, security alerts, KYC notifications) — all of these currently funnel through the same single-provider `OtpDeliveryContract`/`NotificationModuleContract` shape.

**Required change (not implemented in this pass):** introduce a routing layer above the existing per-message contracts — an ordered list of provider bindings plus a channel-fallback rule — while keeping `OtpDeliveryContract` itself unchanged (it's a good per-attempt interface; the gap is the orchestration around it, not the interface). This is a natural candidate for a small Foundation-level `NotificationModuleContract` extension (module-contracts.md:277-286 already stubs this for E17) rather than an E01-only change, since transaction notifications and security alerts (not just OTP) need the same primary/fallback pattern.

No SMS/notification vendor was selected. No SLA values were invented.

---

## 10. Fraud/Risk Boundary Analysis

- E01 does not implement fraud/risk logic, correctly — it only emits the events future risk logic will consume (`Identity.Session.LoginFailed` → E18; `Identity.Device.NewDeviceDetected` → E17/E26).
- E18 (Fraud, Risk & AML) exists only as backlog boilerplate — no real spec content yet, consistent with the master-roadmap dependency order (E18 is sequenced under "Risk & Operations," after the Financial Core and Money Movement epics).
- **Sequencing flag, not a code gap:** the client's §7 explicitly marks fraud/risk capability *"HIGH PRIORITY — REQUIRED"* for Phase 1, using stronger language than most of E18's currently-generic backlog treatment suggests. The client's own Section 18 instructs the team to produce a "Phase 1 SRS" — that document is the correct place to confirm E18's Phase 1 scope explicitly rather than leaving it implicit in roadmap ordering. This is flagged for the Phase 1 SRS deliverable, not for immediate E01/Foundation change.
- The client's compliance-decision-authority rule (§6: *"AI must never independently freeze, confiscate or otherwise make irreversible financial decisions without appropriate governance and authorization"*) has no code implication yet — E01 has no financial-freeze capability at all, and none should be added here; this rule matters once E18/Wallet exist.
- No fraud provider, rules engine, or SLA was invented.

---

## 11. Data Governance / Privacy / Residency Analysis

- **Schema separation** (identity/KYC/financial/AML/audit/communications) is already correctly modeled: nine module-owned Postgres schemas exist, cross-schema JOINs are forbidden at the DB layer and enforced by CI (Pest architecture tests + Deptrac). This satisfies the client's §13 data-category separation requirement structurally, even though most of those schemas (kyc, wallet, ledger, risk, agent) are currently empty.
- **Data minimisation**: no PII duplication or cross-schema leakage was found in E01 or Foundation code — `IdentityModuleContract` deliberately exposes only resolved-actor identifiers, not raw identity records, to other modules.
- **Retention** is the weakest point: only one concrete, configurable retention control exists (`auth.session.retention_days`, default 90), and it's inconsistently applied even within E01 (`OtpChallengeCleanupJob` hard-codes 24 hours instead of reading from configuration). No per-category (KYC/financial/AML/audit/communications/support/AI) retention scheme exists anywhere, and none should be invented now — actual periods are legitimately TBD per the client's own §13 language ("final retention schedule must be documented in the Data Governance framework").
- **IP address handling**: `data-model.md:119` marks IP hashing as *"GDPR compliance TBD"* — correctly left open rather than assumed.
- **AI-generated information** as a data category (client §13) has no corresponding artifact anywhere yet — expected, since no AI-generated-content-producing feature (e.g., Connect's live translation) has been specced.

---

## 12. Production Account / Vendor Governance Analysis

- **Mechanism exists, ownership governance does not.** `SecretProviderInterface`/`EnvSecretProvider` correctly externalizes secrets from code (no hard-coded credentials found anywhere in E01 or Foundation), satisfying FR-075/FR-174 ("secrets MUST be externalized... never committed to version control").
- **No artifact anywhere — spec, plan, task, ADR, or code comment — addresses *who owns* a production provider account** (OleKard vs. an individual developer vs. a vendor). This is a genuine, currently low-consequence gap, because no real provider account exists yet (every provider across KYC/AML/SMS/banking/MNO/cloud is still TBD or a stub).
- **Consequence timing matters here**: this gap should be closed with a lightweight governance policy/ADR *before* the first real vendor account (e.g., the first SMS gateway trial account, or the first sandbox banking API key) is provisioned — not necessarily before further E01 code is written. Recommend flagging this to whoever manages procurement/vendor onboarding rather than solving it in code.

---

## 13. Rail-Agnostic Architecture Analysis

Client's required flow:
```
Customer → App/Connect → Identity & Security → Wallet/Transaction Orchestration →
Integration & Adapter Layer → Bank/MNO/Payment Provider/Utility →
Settlement & Reconciliation → Customer Confirmation
```

Mapped against current state:

| Layer | Current support |
|---|---|
| Customer/App | E31/E32 (Flutter) — scaffolding only |
| Identity & Security | **E01 — implemented**, cleanly separated from every downstream layer via `IdentityModuleContract` and JWT/JWKS local verification (no synchronous coupling) |
| Wallet/Transaction Orchestration | E06/E07/E11 — not built; contracts stubbed only (`WalletModuleContract`, `LedgerModuleContract`, `TransactionModuleContract`), all custody/rail-silent |
| Integration & Adapter Layer | **Missing entirely** — see §6 |
| Bank/MNO/Payment/Utility | Not built (E08, E10, E12/E13, E15) |
| Settlement & Reconciliation | E20 — boilerplate only |
| Customer Confirmation | Depends on Notification module (E17) — stubbed contract only, no routing (§9) |

**Assessment**: the module-per-schema boundary, the outbox/event pattern, and E01's clean isolation from downstream domains all *support* eventual rail-agnosticism — nothing currently built would need to change to accommodate it. The layer that is structurally absent is the Integration & Adapter Layer itself (§6), which is the load-bearing piece of this entire principle. This is the single most consequential gap in the whole analysis, but it is a **build gap**, not a design conflict — no existing code or contract needs to be undone to add it.

---

## 14. 538 Backlog Impact Matrix

| Client Decision | Affected Epic(s) | Affected Feature/Story pattern | Foundation Requirement | E01 Requirement | Integration Contract Needed | Priority |
|---|---|---|---|---|---|---|
| Regulated-partner model, no direct fund custody | E06 Wallet, E07 Ledger, E20 Settlement | All wallet/ledger stories | Custodian/settlement reference field | None | Settlement dual-authority contract | **P1** (before E06/E07 planning) |
| Mobile Money adapter (Orange/MyZaka/BTC Smega) | E08 Funding, E09 Transfer, E10 Withdrawal, E12/E13 Agent/Float | Funding/withdrawal stories referencing MNO rails | `ProviderAdapterInterface` | None | MNO adapter contract | **P1** |
| Banking adapter (multi-bank) | E08 Funding, E10 Withdrawal, E20 Reconciliation | Bank-linked funding/withdrawal/statement stories | `ProviderAdapterInterface` (shared w/ MNO) | None | Banking adapter contract | **P1** |
| KYC orchestration, vendor-neutral | E05 KYC | All 18 KYC stories, esp. `US-KYC-005 Capture selfie` | KYC schema already isolated (done) | Reverse verification-status event (E05→consumers) | KYC orchestration contract | **P2** (when E05 is planned) |
| AML/sanctions/PEP, vendor-neutral | E18 Fraud/Risk/AML | AML-specific stories (currently generic boilerplate) | none yet | Risk-signal event richness review | AML adapter contract | **P2** |
| Fraud/risk Phase-1-required | E18 | All 22 stories | Event payloads from E01 already partially support this | None | Risk-scoring contract | **P0/P1** — needs explicit Phase 1 SRS scoping (§10) |
| OTP/SMS provider-neutral + fallback routing | E01 (partial), E17 Notifications | `US-NOTIFY-003 Send SMS` and all OTP-adjacent stories | `NotificationModuleContract` routing extension | Routing layer above `OtpDeliveryContract` | Provider chain + channel-fallback contract | **P1** |
| Utility Direct Top-Up (BPC/WUC) | E15 Service & Bill Payments | All 11 stories | `ProviderAdapterInterface` (shared) | None | Utility adapter contract (STS/DLMS-COSEM confirmation is D — Pending) | **P2** |
| Settlement/reconciliation, no-silent-resolution | E20 Reconciliation & Settlement | `US-RECON-012/015/016`, `ReconciliationBatch`/`Exception` entities already named in boilerplate | `ProviderReconciliationContract` | None | Reconciliation/exception-queue contract | **P1/P2** |
| API/webhook integration platform | E25 API & Integration Platform | `US-API-008/009/011/012/015/016/017` (already the right shape) | Home for generic adapter contracts (see §6 ADR question) | None | Webhook signature + idempotency contract (can reuse existing outbox/idempotency pattern) | **P1** |
| Security architecture (biometric/WebAuthn/Passkeys) | E26 Security | No current biometric/WebAuthn content in E26 boilerplate | None | TBD-004 stays open (§8) | None | **P3** (documentation alignment once E26 is planned) |
| Multi-currency / rail-agnostic wallets | E29 Multi-Currency Wallet | All 9 stories, `FxRate`/`FxTransaction` entities already named | Depends on Wallet/Ledger custody model (P1 above) | None | None new | **P2** |
| Production account/vendor governance | Cross-cutting (all provider-touching epics) | N/A — governance, not a story | Credential-ownership registry/policy | None | None | **P3 now / P0 at first real account** |
| Data residency neutrality | Cross-cutting | N/A | Optional explicit FR (documentation) | None | None | **P3** |
| Retention, purpose-based | Cross-cutting, esp. E05 KYC, E18 AML, E23 Audit | N/A | Retention-registry extension to `ConfigurationServiceInterface` | Fix `OtpChallengeCleanupJob` hard-coded 24h | None | **P1** (seam) / **P2** (per-module rollout) |

---

## 15. Required Foundation Changes

All are **additive** (no existing converged contract needs to change or break) and all require an ADR per Principle 14 (No Silent Architecture Changes) before implementation:

1. Generic provider-adapter contract family (`ProviderAdapterInterface`, `ProviderCapabilityInterface`, `ProviderHealthInterface`, `ProviderRoutingInterface`, `ProviderFailoverInterface`, `ExternalReferenceMapping`, `ProviderWebhookContract`, `ProviderReconciliationContract`) — location (Foundation vs. E25) is an open ADR question (§6, §19).
2. Notification routing extension (primary/fallback provider + alternate-channel logic) layered above the existing `OtpDeliveryContract`/`NotificationModuleContract` shape.
3. Retention-registry generalization on top of `ConfigurationServiceInterface`, following the existing `auth.session.retention_days` precedent.
4. Settlement dual-authority principle statement (OleKard ledger authoritative for platform state; partner authoritative for its own settlement record).
5. Custodian/settlement-partner reference field for the future `WalletModuleContract`/`LedgerModuleContract` implementations (not the interfaces' current method signatures, which are already custody-neutral and need no change).
6. Optional: explicit cloud-provider-neutrality FR (documentation-only; behavior is already compliant).

None of these require touching E01's converged phases 1–21.

---

## 16. Required E01 Changes

1. **OTP fallback routing** (§9) — new capability, not yet in `tasks.md`; requires a plan amendment, not a rewrite of `OtpDeliveryContract`.
2. **`OtpChallengeCleanupJob` retention fix** — migrate the hard-coded 24-hour cutoff to a configuration key, consistent with the session-retention pattern. Low-risk, small.
3. **Minor doc correction**: `KycServiceProvider.php` stub comment references "E03" where it should say "E05" (KYC is epic 005 in the roadmap, not 003 — 003 is Device & Session Management). Documentation-only.
4. **No change to biometric contracts** — `BiometricAssertionContract` and TBD-004 are correctly left as-is (§8).
5. **Complete Phases 22–23** (E2E scenarios, Polish/Documentation, the outstanding T215 concurrency test) — pre-existing backlog work, not caused by this client document, but should not be deprioritized because of it.

---

## 17. Required Future Epic Changes

None of E02–E34 need spec rewrites in this pass (explicitly out of scope per the task instructions). What this analysis recommends for when each is actually planned:

- **E05 KYC**: define the reverse verification-status event back to consuming modules; adopt the KYC-orchestration adapter pattern from the client's §6 diagram.
- **E06/E07 Wallet/Ledger**: incorporate the custodian/settlement-partner reference and the regulated-partner fund-flow model from day one of planning (§3), not retrofitted later.
- **E15 Utility**: keep STS/DLMS-COSEM protocol selection as D — Pending until confirmed against the actual BPC/WUC target environment, per the client's explicit instruction (§11).
- **E18 Fraud/Risk/AML**: confirm Phase 1 scope explicitly in the client-requested Phase 1 SRS (§10 flag above).
- **E20 Reconciliation/Settlement**: adopt `ProviderReconciliationContract` and the no-silent-resolution principle as first-class requirements, not generic boilerplate.
- **E25 API Integration Platform**: candidate home for the shared adapter contracts (§6) — needs the ADR decision first.
- **E26 Security**: incorporate the auth-vs-KYC-biometric distinction explicitly into its own spec content once planned (currently the E01 separation is correct but E26's own boilerplate doesn't yet reference it).

---

## 18. Explicitly Deferred / TBD Items

Carried forward unresolved, per the client's own No-Assumption Rule — none of these were invented or resolved in this pass:

- TBD-001 — OTP/SMS provider identity.
- TBD-004 — Biometric SDK/protocol details (kept open; see §8 for the non-binding directional recommendation).
- AML provider identity.
- KYC provider identity/providers.
- Specific banks (Bank A/B/C).
- Specific final SMS/OTP provider.
- Cloud infrastructure geography/provider.
- Secrets manager product (Vault vs. AWS Secrets Manager vs. other).
- Utility protocol (STS vs. DLMS/COSEM) pending confirmation against BPC/WUC's actual environment.
- Actual retention periods per data category.
- GDPR/IP-hashing algorithm finalization (data-model.md:119).
- Fraud/risk rules engine specifics and any external risk/fraud provider.
- ODSA™ satellite access — explicitly future, no Phase 1 dependency.

---

## 19. Architecture Decision Records Required

1. **ADR: Location of the generic provider-adapter contract family** — Platform Foundation extension vs. new E25-owned contracts vs. hybrid (generic shape in Foundation, concrete adapters in each consuming epic). *(§6, §15)*
2. **ADR: Notification/OTP fallback-routing architecture** — where the primary/fallback/alternate-channel orchestration lives (Foundation `NotificationModuleContract` extension vs. E01-local vs. E17-owned). *(§9)*
3. **ADR: Retention-registry model** — generalized configuration-driven retention scheme, extending the existing session-retention precedent. *(§11, §4)*
4. **ADR: Settlement dual-authority statement** — formalizing "OleKard ledger authoritative for platform state; partner authoritative for settlement" as a Foundation or E20-level principle. *(§4, §13)*
5. **ADR: Credential/production-account ownership governance** — policy-level, likely not a code ADR but should be tracked with the same rigor before the first real vendor account exists. *(§12)*

None of these ADRs were authored in this pass — they are logged as required, per instruction.

---

## 20. Recommended Change Sequence

1. Continue and complete E01 Phases 22–23 (E2E + Polish) — independent of this client document, already in flight.
2. Author ADR-006 (provider-adapter location) and ADR-007 (notification routing) — these gate all downstream epic planning (E08, E10, E12, E15, E20, E25), not E01.
3. Apply the two small, low-risk E01 fixes (§16.2, §16.3) opportunistically — e.g., folded into the Polish phase.
4. Extend Platform Foundation with the additive contracts from §15, sequenced just ahead of whichever epic needs them first (likely E05 KYC or E08 Funding, per roadmap dependency order).
5. Only then begin detailed planning (`speckit-plan`/`speckit-tasks`) for E05, E08, E10, E12/E13, E15, E18, E20, E25 — each should consume the now-defined adapter/routing/retention/settlement contracts rather than re-deriving them independently.
6. Revisit E18 Fraud/Risk sequencing explicitly in the client-requested Phase 1 SRS deliverable.

---

## 21. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Each downstream epic (MNO, banking, KYC, AML, utility) invents its own divergent adapter pattern because no shared contract exists yet | Medium | High (rework, inconsistent reconciliation/webhook handling) | Resolve ADR-006 before any of E08/E10/E12/E15/E20/E25 detailed planning starts |
| OTP/notification delivery has no failover, so a single provider outage blocks registration/login/step-up entirely | Medium | High (production availability) | Prioritize the notification-routing extension (§9) before selecting a real OTP/SMS vendor |
| Retention inconsistency (hard-coded vs. configurable) spreads as more modules land, creating a compliance patchwork | Medium | Medium | Resolve the retention-registry ADR early, before KYC/AML modules start persisting real data |
| Production vendor accounts get provisioned under individual-developer ownership before governance is defined | Low now, rising over time | High (production account custody/compliance risk) | Close the credential-ownership ADR before the first real (non-stub) provider account is created |
| E18 Fraud/Risk is treated as fully deferred despite the client marking it Phase-1-required | Medium | Medium-High (regulatory/compliance exposure if launched without it) | Explicit Phase 1 SRS scoping decision (§10, §17) |
| WebAuthn/Passkey preference is silently over-interpreted as a mandate, causing premature SDK commitment | Low (this analysis explicitly resists that) | Medium (rework if a different SDK is later required) | Keep TBD-004 open per §8; do not resolve without explicit client confirmation |

---

## 22. No-Assumption Register

Confirms nothing was invented in this pass:

- No vendor, SDK, or library was selected for biometrics, OTP/SMS, KYC, AML, banking, MNO, utility, or secrets management.
- No cloud provider or region was chosen.
- No retention period value was invented.
- No performance/availability SLA was invented.
- No pending client decision (TBD-001, TBD-004, AML/KYC/bank/MNO/SMS provider identity, utility protocol, cloud geography) was resolved.
- Auth-biometric and KYC-biometric were not conflated anywhere in this report.
- No cross-schema JOIN was proposed.
- No Platform Foundation contract was bypassed; all recommended changes are additive extensions, not replacements.
- The 538-story backlog was not rewritten — only mapped for impact.
- One incidental documentation error was noted (`KycServiceProvider.php` comment says "E03," should say "E05") — flagged in §16.3 as a doc fix, not acted on in this pass.
- One tasks.md hygiene anomaly was noted by the E01 research pass — a task-ID collision/duplicate "Phase 24: Convergence" heading in `specs/001-central-identity-authentication/tasks.md` (T215/T216 appearing twice with different content, phase heading repeated at lines 438 and 551). This is unrelated to the client architecture document and is flagged here only for completeness; it should be resolved as ordinary spec hygiene, not as part of this impact analysis.

---

## 23. Final Recommendation

**Continue development unchanged for E01** (finish Phases 22–23 as already planned).

**Continue after a scoped Foundation update** for everything downstream of E01: before detailed planning begins on E05 KYC, E08 Funding, E10 Withdrawal, E12/E13 Agent/Float, E15 Utility, E18 Fraud/Risk/AML, E20 Reconciliation/Settlement, or E25 API Integration Platform, resolve ADR-006 (provider-adapter location) and ADR-007 (notification routing) at minimum — these two gate the largest share of the client's requirements and touch the most epics.

**Do not pause implementation.** No conflict was found between OKI-ARCH-2026-003 and anything already built or converged; every identified gap is a missing seam for work that hasn't started yet, not a defect in E999 or E01.
