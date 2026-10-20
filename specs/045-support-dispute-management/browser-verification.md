# Browser visual verification
Chromium: 3 PASS. Evidence: browser-final.log and browser-results.json.
- 1440x1000 desktop queue: required fields, filters, pagination and explicit Overdue state.
- Case detail: customer/transaction links, description, existing evidence, internal conversation, selected escalation reason and 24-hour deadline.
- Evidence download: authenticated client sends Bearer/correlation headers and produces the expected PDF filename.
- Refund: amount/reason review, forced MFA challenge, explicit verify/confirm, idempotency header and resolved-state actions.
- 390x844: stacked cards, wrapping identifiers, no page-level horizontal overflow; keyboard focus verified.
- Permission-only customer session is redirected to /403 before any Support fetch.
- Verification code is masked in screenshot; no localStorage credentials.

Screenshots visually inspected: screenshots/queue-desktop.png, screenshots/case-desktop.png, screenshots/case-mobile.png, screenshots/refund-confirmation.png.
API responses in these UI journeys are isolated synthetic fixtures. Real database/security/financial/notification behavior is covered separately by backend tests.
