# Research
Decision: reuse RiskAlert/AlertService/AccountHoldService and add adapters; rejected copying services or user-status/wallet shortcuts.
Decision: additive findings enum/constraint, retain suppression.
Decision: independent authenticated checker request/approval, not a trusted form UUID.
Research agent confirmed existing freeze accepts arbitrary checker UUID. IdentityModuleContract supplies hasPermission and step-up interfaces.
OutboxEventPublisher requires platform transaction; risk holds use risk connection. New workflow must use durable Risk outbox or align transactions without unsafe distributed writes.
NotificationService/createFromEvent deduplicates source_event_id/type and uses templates/configured rules. ACCOUNT_FROZEN_AML integration absent and must be added.
Evidence context comes from RiskEvents/Evaluations/decisions; no synthetic financial data.
