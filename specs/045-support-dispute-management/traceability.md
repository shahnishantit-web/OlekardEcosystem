# Story-to-requirement traceability
Source: docs/admin-p1p2-epic-catalogue.md ADM-P1-021; supporting E22 spec and Constitution.
| Story | AC | Tasks | Implementation | Verification | Status |
|---|---|---|---|---|---|
| US-P1-021-001 | AC01-02 | T002-T004,T012-T013 | AdminCaseService queue, AdminSupportController, case-queue.tsx, navigation/middleware | Queue filters/date/pagination/overdue API tests; queue Jest/browser | PASS |
| US-P1-021-002 | AC03-04 | T005-T006,T011-T013 | AdminCaseService detail/mutate/attach/download; private metadata migration; case-detail.tsx | Existing context, mixed-time note order, notes/links, MIME/size/count/scoped retrieval; 10 MiB boundary; browser protected download | PASS |
| US-P1-021-003 | AC05-07 | T007-T009,T012-T013 | ActorStepUpVerifierContract/IdentityModule; shared AdminDisputeRefundService; legacy compatibility facade; SupportRefundAdapter; Notification listener/templates | Real MFA/PIN, forged/expired/wrong scope/actor/session, permissions, central policy, balanced journal/fee reversal/history, replay/different-key guard/cross-connection lock/rollback/cumulative cap; real in-app delivery; browser confirm | PASS |
| US-P1-021-004 | AC08 | T010-T013 | AdminCaseService escalation; AdminCaseEvent; SupportCaseNotificationListener; detail UI | Exact 24-hour deadline, eligible senior, repeated escalation conflict, overdue and durable E17 notification | PASS |
| Constitution / FR01-FR07 | All | T001,T012-T014 | Existing contracts, shared tokens, server RBAC, immutable ledger, activity/outbox, OpenAPI | 78 Support + 62 related backend, 118 frontend, 3 browser, static/types/lint/architecture and manual source review | PASS |

Financial compensation follows E22's liability-funded dispute refund model using the existing supported credit journal type and a linked type=refund transaction. Existing generic reversal services were reviewed; the verified Ledger/Fees/Wallet/Transaction contracts supply this flow.
E22 approval remains the prerequisite for refund. Existing approval endpoints and configured independent confirmation controls are retained.
No approved ordinary support SLA value was invented. The Admin escalation SLA is explicitly 24 hours.
