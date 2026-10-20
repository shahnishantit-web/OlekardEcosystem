# Spec Kit workflow and traceability
- Specify: approved catalogue ADM-P1-019 and E17 source reviewed; feature spec and quality checklist created.
- Clarify: mandatory precedence, shared channel copy, nullable legacy language and provider limitations resolved from existing sources; no invented TBD business value.
- Plan: setup-plan.ps1 -Json executed; research agent inspected E17/races; research/data-model/contracts/quickstart generated; constitution gates pass before/after design.
- Tasks: setup-tasks.ps1 -Json executed; 14 dependency-ordered tasks for all three stories, tests included.
- Analyze (read-only assessment before implementation): all FR/AC covered, no unresolved critical/high artifact finding. FR001/AC1–3 -> T003–T005; FR002/AC4–6 -> T006–T008; FR003/AC7–8 -> T009–T011; FR004–006/SC001–003 -> T002,T012–T014; FR007 -> T004,T005,T013.
- Implement: prerequisite/checklist/ignore checks completed. Red acceptance run failed on absent migration; green implementation/regression evidence retained. Tasks marked only after corresponding work.
- Converge: final separate read-only source/artifact assessment recorded in convergence.md.
- Extension hooks: .specify/extensions.yml absent; pre/post hooks skipped as prescribed.
Claude availability check: HTTP 429, weekly reset Oct 16 at 9:30pm Asia/Calcutta. User selected Continue with Codex + Spec Kit. No model/provider bypass.
Source governance: CLAUDE.md, .specify/memory/constitution.md, specs/000-master-roadmap/spec.md, specs/017-notifications/spec.md, docs/admin-p1p2-epic-catalogue.md and completed specs/042-fraud-risk-aml-dashboard reviewed.
