# Wallet Ecosystem — Spec-Kit Project

## Approved stack
- Laravel/PHP 8.4+ — Backend APIs
- PostgreSQL — Primary relational database
- Redis + Laravel Horizon — Cache/queues
- Next.js + TypeScript — Admin/Enterprise web
- Flutter — Customer and Agent mobile
- Shared token-driven design system

## Source of truth
The approved 538-story formal implementation specification is the business/functional baseline.

## Spec-Kit workflow

Current Spec Kit uses these agentic commands:
1. `/speckit.constitution`
2. `/speckit.specify`
3. `/speckit.clarify` when needed
4. `/speckit.plan`
5. `/speckit.checklist`
6. `/speckit.tasks`
7. `/speckit.analyze`
8. `/speckit.implement`
9. `/speckit.converge`

For this large product, use the **spec-of-specs** approach: work one epic/sub-spec at a time rather than trying to implement the whole 538-story product in one cycle.

## First-time setup

Use the current Spec Kit CLI to initialize the repository, then preserve this project structure.

Example:
```bash
uv tool install specify-cli
specify init . --integration claude
specify version
```

The exact integration command can vary by installed Spec Kit version; verify with `specify --help`.

## First execution sequence

1. Review `.specify/memory/constitution.md`
2. Review `specs/000-master-roadmap/spec.md`
3. Start with the first dependency-ready epic.
4. Run `/speckit.specify` only when creating/updating a feature spec.
5. Run `/speckit.clarify` without inventing business values.
6. Run `/speckit.plan`.
7. Run `/speckit.checklist`.
8. Run `/speckit.tasks`.
9. Run `/speckit.analyze`.
10. Human review.
11. Run `/speckit.implement`.
12. Run `/speckit.converge`.
13. Move to the next dependency-ready epic.

## Non-negotiable
- No silent changes to requirements.
- No invented TBD values.
- No direct wallet-balance mutation as a substitute for ledger posting.
- No unbalanced financial journals.
- No duplicate financial effects.
