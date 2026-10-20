# Research
Decision: reuse E29 rate records and E28 requests. Rationale: approved maker-checker semantics and existing financial integrations. Alternative: duplicate approval engine rejected.
Decision: explicit provider port, unavailable default. No approved FX vendor found in E29 or provider registry; generic provider adapter does not define FX quote semantics. No fabricated production data.
Decision: preserve fractional spread API (decimal6,5) and rate18,8; UI percent conversion uses string decimal arithmetic. Avoid rounding silently.
Decision: PostgreSQL transaction advisory pair/idempotency locks and E28 approval verification repair inherited replay/self-approval/race gaps. Existing unique active-pair index remains.
Decision: base central multi_currency.base_currency can be unconfigured; display Unconfigured and do not assert BWP base without approved config.
