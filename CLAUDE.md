# Claude Code Project Instructions

You are implementing the Wallet Ecosystem using Spec-Driven Development.

Read and follow:
1. `.specify/memory/constitution.md`
2. `specs/000-master-roadmap/spec.md`
3. The active feature's `spec.md`, `plan.md`, and `tasks.md`

Approved stack:
- Laravel / PHP 8.4+ for backend APIs
- PostgreSQL
- Redis + Laravel Horizon
- Next.js + TypeScript for Admin/Web
- Flutter for Customer and Agent mobile applications
- Shared design tokens/components
- REST/OpenAPI

Non-negotiable:
- Do not invent TBD values.
- Do not directly mutate wallet balances as a substitute for ledger posting.
- Do not alter historical ledger entries.
- Use double-entry journals for financial effects.
- State-changing financial APIs require idempotency.
- Test concurrency and duplicate processing for financial flows.
- Enforce authorization server-side.
- Audit privileged/security/financial actions.
- Do not change the approved technology stack without an ADR.
- Keep module boundaries explicit and extraction-ready.
- Keep controllers thin; business logic belongs in domain/application services.
- Update tests and documentation with implementation changes.

For each implementation cycle:
1. Review the active spec.
2. Review the plan and constitution check.
3. Implement only the approved tasks.
4. Run tests.
5. Review for security, financial integrity, accessibility and regressions.
6. Update artifacts if implementation reveals a genuine requirement gap; do not silently modify requirements.
