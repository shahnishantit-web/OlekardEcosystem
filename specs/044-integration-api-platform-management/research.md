# Research
Spec Kit research agent integration_research reviewed existing sources.
- Decision: reuse E25 ApiClientApplicationService/outbox on platform connection; append DTO defaults for compatibility. Rationale: hashing/random credentials/audit already work. Alternative duplicated generation rejected.
- Decision: immutable revoke marker plus existing inactive state; lock credential mutations. Rationale: prevent legacy activation/rotation from reviving revoked clients.
- Decision: stored IP/CIDR allowlist validated and enforced on actual trusted request IP. Empty preserves legacy access.
- Decision: callback log means inbound processing. Rationale: approved E25 has no outbound delivery model; derive receiver path and document processed→Delivered.
- Decision: durable retry intent plus shared worker lock/completed guard/generation protection. Rationale: automatic delayed retries otherwise race manual retries.
- Decision: extend existing Ops collector with integration inventory contract and integration_id samples. Rationale: type-only default data cannot represent every partner. No concrete probe implementations found, so unprobed providers show Not checked.
- Decision: 24 UTC hourly sampled-uptime buckets and coverage; no interpolation or invented SLA.
- Alternative arbitrary endpoint HTTP probing rejected: risks SSRF and invented provider semantics.
