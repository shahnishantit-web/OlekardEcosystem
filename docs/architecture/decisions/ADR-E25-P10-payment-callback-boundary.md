# E25 payment callback ownership boundary

Date: 2026-10-22. Status: implemented technical boundary; live activation review pending.
Authority: architecture v1.1 section 1, FD06 and LC05; user-authorized P10 scope.

E25 owns shared connector lifecycle, raw verification dispatch, receipt storage,
delivery identity, transport acknowledgement, encrypted evidence, retry/quarantine
and audit. Payments registers a trusted account parser factory and semantic
consumer through E25 extension bindings. Payments owns capability contracts and
validates money/account before delegating to its existing outcome processor.
It returns quarantine reasons; E25 commits transport state and outbox together.

The Payment callback URL is retained as an E25 facade, not a second raw ingress
store. Legacy unverified jobs are quarantined. Wallet balances and authoritative
ledger posting remain in their current modules. Provider HTTP contracts,
certification and live semantic readiness remain separately blocked.

This resolves the technical ownership ambiguity tracked as DEC-06 in the P00
register. It does not claim Security, Finance or provider certification approval.
