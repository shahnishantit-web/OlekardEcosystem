# Target Architecture

## Current implementation baseline
Laravel modular monolith + PostgreSQL + Redis/Horizon.

## Enterprise target state
The supplied enterprise architecture describes a microservices-oriented application layer with bounded-context services communicating through internal APIs and asynchronous events. The modular-monolith implementation must preserve explicit boundaries so later extraction is possible.

## Applications
- Customer: Flutter
- Agent: Flutter
- Admin/Enterprise: Next.js
- Backend: Laravel APIs

## Core domains
Identity, User, Authorization, KYC, Wallet, Ledger, Transaction, Payment, Withdrawal, Risk, Agent, Commission, Notification, Reconciliation, Settlement, Reporting, Audit, Configuration.

## Core financial flow
Request → Authentication → Authorization → Validation → Policy/Limits → Risk → Idempotency → Concurrency Control → Ledger Posting → Transaction State → Events → Notification → Audit → Reconciliation.
