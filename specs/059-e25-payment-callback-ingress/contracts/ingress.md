# Callback ingress and capability contracts

Canonical POST `/v1/webhooks/{providerSlug}` remains E25 public raw-body ingress.
Legacy POST `/api/v1/payment/callbacks/{providerCode}` becomes an E25 facade and
requires exactly one integration mapped via configuration `payment_provider_code`.
Multiple accounts use distinct E25 slugs, never a payload tenant selector.

Development account protocol: `X-Signature` HMAC-SHA256 over exact body;
JSON `{event_id,transaction_ref,merchant_account,amount,currency,status}`.
Status success/failed/pending/unknown; stable event_id mandatory. Amount is integer
minor units. `secret_reference` uses foundation environment-secret names in this
implementation; an account must reference a nonempty external secret. No actual
secret stored in integration config, response or log. Provider onboarding must
supply its own reviewed parser/transport; no mock verifier enabled in production.

200/202 ACK proves durable receipt only. 401 bad signature, 404 unmapped provider,
409 conflicting event identity, retryable 5xx before durability. After commit a
queue failure preserves receipt for operator retry. Disabled Payments provider
does not prevent resolution; E25 inactive resolution needs explicit configuration.

Separate initiate/query/refund/payout interfaces are Payments-owned. Legacy
composite extends the first three; payout is never implicitly available.
Unsupported capability fails with CapabilityUnsupportedException before effect.

Operator routes reuse authenticated E25 delivery list and audited retry, with
quarantine action. No public raw evidence endpoint. Ordinary resources omit
encrypted evidence and raw bytes. Live semantic activation remains blocked by
verified predecessor readiness, separate from transport ACK.
