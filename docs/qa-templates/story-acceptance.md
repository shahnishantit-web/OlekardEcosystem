# Story Acceptance Template

## Story Information

| Field | Value |
|-------|-------|
| Story ID | <!-- e.g., US-042 --> |
| Story Title | <!-- Short description --> |
| Epic | <!-- Parent epic name/ID --> |
| Tester | <!-- Name of QA engineer --> |
| Date | <!-- YYYY-MM-DD --> |
| Build / Tag | <!-- Git tag or commit SHA --> |
| Environment | <!-- staging / test --> |

---

## Acceptance Criteria

| # | Criterion | Test Type | Test ID | Result | Evidence Link |
|---|-----------|-----------|---------|--------|---------------|
| 1 | <!-- Criterion text from spec --> | <!-- unit / integration / e2e / manual --> | <!-- e.g., T-1234 --> | <!-- PASS / FAIL / SKIP --> | <!-- URL or file path --> |
| 2 | | | | | |
| 3 | | | | | |

**Overall AC Result**: <!-- PASS / FAIL -->

---

## Security Validation

- [ ] Authentication enforced on all protected endpoints
- [ ] Authorization rules verified (role cannot exceed scope)
- [ ] PII not present in application logs (checked correlation-ID-filtered log output)
- [ ] Input validated and sanitized at API boundary
- [ ] Idempotency-Key accepted and duplicate requests rejected correctly
- [ ] Audit log entries created for privileged/financial operations

**Security Result**: <!-- PASS / FAIL / N/A -->
**Notes**: <!-- Any observations or exceptions -->

---

## Performance Validation

| Endpoint / Operation | p50 (ms) | p95 (ms) | p99 (ms) | Target | Result |
|----------------------|----------|----------|----------|--------|--------|
| <!-- e.g., POST /v1/wallets --> | | | | <!-- e.g., p95 ≤ 200ms --> | <!-- PASS/FAIL --> |

**Load tool used**: <!-- e.g., k6, Artillery -->
**Test duration / RPS**: <!-- e.g., 60s at 50 RPS -->
**Performance Result**: <!-- PASS / FAIL / N/A -->

---

## Accessibility

- [ ] axe-core scan run (web) — zero violations
- [ ] Keyboard navigation tested: all interactive elements reachable via Tab/Enter/Escape
- [ ] Screen reader tested: meaningful labels announced (NVDA / VoiceOver)
- [ ] Minimum 44×44 px touch targets verified (mobile)
- [ ] Reduced-motion preference respected

**Accessibility Result**: <!-- PASS / FAIL / N/A -->
**Notes**: <!-- Findings or known deviations -->

---

## Sign-off

| Role | Name | Date | Signature / Approval |
|------|------|------|----------------------|
| QA Engineer | | | |
| Feature Lead | | | |
| Security Reviewer (if required) | | | |
