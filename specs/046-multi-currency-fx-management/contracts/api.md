# ADM022 additive contracts
Existing /v1/admin/multi-currency/currencies and fx-rates preserved. Catalogue aliases /v1/admin/currencies, /v1/admin/fx/rates.
Currency PATCH /currencies/{code}: metadata fields remain; status changes create E28 pending request; precision changes are rejected, UUID Idempotency-Key required. Returns currency plus pending_change_request_id. POST /currencies/{code}/activate/{request}: checker applies approved exact proposal; configuration.approve required.
GET /fx/rates: active rows plus pending rows to allow checker processing; each has source/provider/observed_at, status and approved metadata.
POST /fx/rates: from/to, rate (positive decimal18,8), spread (fraction0<=x<1,scale5), mode manual/provider; provider mode obtains authoritative snapshot,503 if unavailable. Returns pending rate. POST /fx/rates/{id}/activate: distinct checker approves using E28 then applies, UUID key. Existing E29 activation requires already approved E28 change, cannot bypass.
GET /fx/rates/{pair}/history: pair FROM-TO, page>=1,per_page1..100; data {chart:[active historical points from last30days],history:[],pagination:{...}}, source + provider + timestamps.
Permissions existing multi_currency.manage_currencies, multi_currency.configure_rate; checker additionally configuration.approve. No automatic role grants.
