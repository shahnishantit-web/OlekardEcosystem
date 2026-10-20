# Implementation plan ADM-P1-021
## Technical context
Laravel PHP8.4/PostgreSQL platform transaction, Redis outbox, Next.js/TypeScript, existing shared UI. No new framework.
## Constitution gate
PASS design: exact source AC; ordinary SLA remains unset; server RBAC; actor/session-bound step-up; row locking + immutable journal and financial projection; existing Ledger/Fees/Wallet contracts; durable events; private storage; correlation/audit; additive APIs. Release gate pending verification.
## Research
Delegated read-only refund investigation completed. Existing E22 refund and generic Transaction reversal services are not safe shortcuts. All financial records share platform connection. Extend narrow contracts within owning modules. Existing NotificationModuleContract implementation is null: integrate using Notification-owned event listener and real NotificationService.
## Design
Unify existing SupportTicket/Dispute via SQL union (exclude linked ticket duplicates). Add Admin metadata/activity/private attachment tables; preserve original enum/state machines. Reuse ticket note/status/assignment and dispute investigation where valid. Escalation stores explicit 24-hour UTC deadline; senior must hold support.assign + support.investigate. Financial adapter supplies authoritative original and persists linked refund projection. Atomic outer platform transaction locks dispute and original, validates caps, posts actual wallet-pocket journal, reverses fees, writes outcome/outbox. Maker-checker uses existing toggle and independent operator. Idempotency payload hashes stored with completed activity; replay after authorization/MFA; different-key duplicate blocked by terminal state and row lock.
## Sequence
Tests/scaffolding -> queue/detail -> investigation/private evidence -> refund/MFA/notifications -> escalation -> UI -> regression/browser -> convergence/status.
