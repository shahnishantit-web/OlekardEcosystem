# Implementation Map

## Backend Laravel
`services/api/`
- Domain/application logic follows the Epic boundaries.
- API controllers remain thin.
- PostgreSQL migrations live under the Laravel service.
- Shared event/outbox infrastructure lives in platform infrastructure modules.

## Next.js
`apps/admin/`
- Administration and Enterprise web experiences.
- Desktop-first with tablet support.
- Mobile read/approve behavior only where appropriate.
- Uses shared design tokens.

## Flutter
`apps/customer/`
- Customer mobile app.

`apps/agent/`
- Agent mobile app.

## Design System
`packages/design-system/`
- Token source
- Component contracts
- Accessibility rules
- Theme mappings

## Spec-Kit mapping
- `specs/000-master-roadmap/` = spec-of-specs
- `specs/<epic>/spec.md` = source requirements for the epic
- `specs/<epic>/plan.md` = technical implementation plan
- `specs/<epic>/tasks.md` = implementation task breakdown
- `CLAUDE.md` = Claude Code operating rules
- `.specify/memory/constitution.md` = project governance
