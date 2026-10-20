# Admin panel link audit

Completed: 2026-10-19T08:33:19.105Z

Origin: http://localhost:3000. Authenticated with the local administrator.

- Checked **197 unique page paths**, including rendered sublinks and verified existing-record detail URLs.
- Remaining page HTTP 404 responses: **0**.
- Observed page API HTTP 404 responses: **0**.
- Other unexpected page HTTP/navigation errors: **0**.
- Statically resolved admin GET references: **95**, unmatched registered routes: **0**.
- Requests were read-only apart from authentication. No financial or administrative forms were submitted.

Coverage correction: these page-status results did not prove every underlying API
parameter was correct. A subsequent configuration audit found that the detail page
duplicated the domain in API keys (`auth.auth.biometric.enabled`). The route now
passes the full key unchanged. See `configuration-key-audit.json` for the dedicated
registry-wide detail/history checks, including browser requests by domain.

## Repairs

- Added missing Payment Providers list/create/detail/edit and Payment Exceptions page routes, using the existing screens.
- Corrected payment API paths to the registered `/api/v1/admin/payment/...` prefix.
- Corrected the reconciliation Back link and added `/reconciliation` compatibility redirection.
- Repaired New Report navigation to focus the existing request form, and redirected `/reports/catalogue` to it.
- Corrected three Community Campaign route imports which previously caused HTTP 500 compilation failures.
- Corrected stale withdrawal controller namespaces/action references discovered while exporting the backend route inventory.

Four originally probed missing page URLs returned HTTP 404: `/payment/providers`, `/payment/providers/create`, `/payment/reconciliation/exceptions`, and `/reconciliation`.

## Verification

- Payment UI: 36 tests passed.
- Reports UI, including New Report focus: 32 tests passed.
- Reconciliation UI, including Back navigation: 39 tests passed.
- Backend route inventory exports successfully; all 95 resolved GET references match registered route patterns.

## Scope and remaining observations

Repeated navigation links are checked once per pathname; query filters and fragments share the same page route. External links and business-action buttons are excluded. Detail URLs use existing records; no dummy IDs or seed records were created.

The dedicated `/401`, `/429`, `/503`, and `/504` demonstration pages intentionally return those HTTP statuses.

Non-404 API responses observed separately: payment provider/exception reads return HTTP 403 for this account, and a dashboard transaction query using `per_page=500` returns HTTP 422. Authorization was not relaxed.

Detail patterns without a successfully visited existing-record URL (empty data or restricted access):

- `/community/campaigns/[id]`
- `/community/orders/[id]`
- `/community/organisations/[id]`
- `/community/staff/[orgId]`
- `/fees/rules/[id]`
- `/integrations/[id]`
- `/payment/providers/[id]/edit`
- `/payment/providers/[id]`
- `/qa/runs/[id]`
- `/reports/[id]`

Supplemental record-discovery API limitations:

- `/api/v1/admin/payment/providers`: 403 (AUTHORIZATION_001)

## Checked URLs

| Page | HTTP | Destination |
| --- | ---: | --- |
| [/](http://localhost:3000/) | 200 | / |
| [/401](http://localhost:3000/401) | 401 | /401 |
| [/403](http://localhost:3000/403) | 200 | /403 |
| [/429](http://localhost:3000/429) | 429 | /429 |
| [/503](http://localhost:3000/503) | 503 | /503 |
| [/504](http://localhost:3000/504) | 504 | /504 |
| [/account-disabled](http://localhost:3000/account-disabled) | 200 | /account-disabled |
| [/account-locked](http://localhost:3000/account-locked) | 200 | /account-locked |
| [/admin-portal/agents](http://localhost:3000/admin-portal/agents) | 200 | /admin-portal/agents |
| [/admin-portal/agents/01a124db-7047-73d1-b688-7edc5096a75f](http://localhost:3000/admin-portal/agents/01a124db-7047-73d1-b688-7edc5096a75f) | 200 | /admin-portal/agents/01a124db-7047-73d1-b688-7edc5096a75f |
| [/admin-portal/agents/4196cf5c-871c-4a1f-8526-a72e906d3106](http://localhost:3000/admin-portal/agents/4196cf5c-871c-4a1f-8526-a72e906d3106) | 200 | /admin-portal/agents/4196cf5c-871c-4a1f-8526-a72e906d3106 |
| [/admin-portal/dashboard](http://localhost:3000/admin-portal/dashboard) | 200 | /admin-portal/dashboard |
| [/admin-portal/float](http://localhost:3000/admin-portal/float) | 200 | /admin-portal/float |
| [/admin-portal/roles](http://localhost:3000/admin-portal/roles) | 200 | /admin-portal/roles |
| [/admin-portal/roles/01a13892-611d-71df-8db6-507aee30f221](http://localhost:3000/admin-portal/roles/01a13892-611d-71df-8db6-507aee30f221) | 200 | /admin-portal/roles/01a13892-611d-71df-8db6-507aee30f221 |
| [/admin-portal/roles/01a13892-63d8-7073-abf2-4471017c79d6](http://localhost:3000/admin-portal/roles/01a13892-63d8-7073-abf2-4471017c79d6) | 200 | /admin-portal/roles/01a13892-63d8-7073-abf2-4471017c79d6 |
| [/admin-portal/roles/01a13893-8c11-7252-b4ec-3ec656514b39](http://localhost:3000/admin-portal/roles/01a13893-8c11-7252-b4ec-3ec656514b39) | 200 | /admin-portal/roles/01a13893-8c11-7252-b4ec-3ec656514b39 |
| [/admin-portal/roles/01a13893-a544-715c-9f74-36ff1ef0b9ab](http://localhost:3000/admin-portal/roles/01a13893-a544-715c-9f74-36ff1ef0b9ab) | 200 | /admin-portal/roles/01a13893-a544-715c-9f74-36ff1ef0b9ab |
| [/admin-portal/roles/01a13893-a7b0-7038-963b-3c7f4a3ff359](http://localhost:3000/admin-portal/roles/01a13893-a7b0-7038-963b-3c7f4a3ff359) | 200 | /admin-portal/roles/01a13893-a7b0-7038-963b-3c7f4a3ff359 |
| [/admin-portal/roles/01a13893-aace-7169-9896-67980440063d](http://localhost:3000/admin-portal/roles/01a13893-aace-7169-9896-67980440063d) | 200 | /admin-portal/roles/01a13893-aace-7169-9896-67980440063d |
| [/admin-portal/roles/01a13893-af3c-7123-8b12-5900e7238a61](http://localhost:3000/admin-portal/roles/01a13893-af3c-7123-8b12-5900e7238a61) | 200 | /admin-portal/roles/01a13893-af3c-7123-8b12-5900e7238a61 |
| [/admin-portal/roles/01a13893-b36c-7235-af52-f012ad368978](http://localhost:3000/admin-portal/roles/01a13893-b36c-7235-af52-f012ad368978) | 200 | /admin-portal/roles/01a13893-b36c-7235-af52-f012ad368978 |
| [/admin-portal/roles/01a13893-ba9d-7104-afbc-18f2ee39ba07](http://localhost:3000/admin-portal/roles/01a13893-ba9d-7104-afbc-18f2ee39ba07) | 200 | /admin-portal/roles/01a13893-ba9d-7104-afbc-18f2ee39ba07 |
| [/admin-portal/roles/01a13893-be15-7325-bddf-55c41209e6b6](http://localhost:3000/admin-portal/roles/01a13893-be15-7325-bddf-55c41209e6b6) | 200 | /admin-portal/roles/01a13893-be15-7325-bddf-55c41209e6b6 |
| [/admin-portal/roles/01a13893-c048-722a-b7f9-5f12eec1d0df](http://localhost:3000/admin-portal/roles/01a13893-c048-722a-b7f9-5f12eec1d0df) | 200 | /admin-portal/roles/01a13893-c048-722a-b7f9-5f12eec1d0df |
| [/admin-portal/roles/01a13893-c344-732f-91f7-b957128787f2](http://localhost:3000/admin-portal/roles/01a13893-c344-732f-91f7-b957128787f2) | 200 | /admin-portal/roles/01a13893-c344-732f-91f7-b957128787f2 |
| [/admin-portal/roles/01a13893-c5fc-7080-a181-048e47149f5b](http://localhost:3000/admin-portal/roles/01a13893-c5fc-7080-a181-048e47149f5b) | 200 | /admin-portal/roles/01a13893-c5fc-7080-a181-048e47149f5b |
| [/admin-portal/roles/01a13893-c876-71e8-ae82-3198ccdae39b](http://localhost:3000/admin-portal/roles/01a13893-c876-71e8-ae82-3198ccdae39b) | 200 | /admin-portal/roles/01a13893-c876-71e8-ae82-3198ccdae39b |
| [/admin-portal/roles/01a13893-cc20-70da-b676-96ba2de500e4](http://localhost:3000/admin-portal/roles/01a13893-cc20-70da-b676-96ba2de500e4) | 200 | /admin-portal/roles/01a13893-cc20-70da-b676-96ba2de500e4 |
| [/admin-portal/roles/01a13893-ce88-70f3-803e-5b736bcb36cd](http://localhost:3000/admin-portal/roles/01a13893-ce88-70f3-803e-5b736bcb36cd) | 200 | /admin-portal/roles/01a13893-ce88-70f3-803e-5b736bcb36cd |
| [/admin-portal/roles/01a13893-d24d-7291-9fab-67ede20abd07](http://localhost:3000/admin-portal/roles/01a13893-d24d-7291-9fab-67ede20abd07) | 200 | /admin-portal/roles/01a13893-d24d-7291-9fab-67ede20abd07 |
| [/admin-portal/roles/01a13893-d476-72fa-910c-0acb20a2a227](http://localhost:3000/admin-portal/roles/01a13893-d476-72fa-910c-0acb20a2a227) | 200 | /admin-portal/roles/01a13893-d476-72fa-910c-0acb20a2a227 |
| [/admin-portal/roles/01a13895-536e-71b2-a3c8-59f98d853410](http://localhost:3000/admin-portal/roles/01a13895-536e-71b2-a3c8-59f98d853410) | 200 | /admin-portal/roles/01a13895-536e-71b2-a3c8-59f98d853410 |
| [/admin-portal/roles/01a13895-571d-7324-8186-c26869324dda](http://localhost:3000/admin-portal/roles/01a13895-571d-7324-8186-c26869324dda) | 200 | /admin-portal/roles/01a13895-571d-7324-8186-c26869324dda |
| [/admin-portal/roles/01a13895-5928-7358-a757-163bb0b1cc0c](http://localhost:3000/admin-portal/roles/01a13895-5928-7358-a757-163bb0b1cc0c) | 200 | /admin-portal/roles/01a13895-5928-7358-a757-163bb0b1cc0c |
| [/admin-portal/roles/01a13895-5b32-700b-ab4e-5a75ce239658](http://localhost:3000/admin-portal/roles/01a13895-5b32-700b-ab4e-5a75ce239658) | 200 | /admin-portal/roles/01a13895-5b32-700b-ab4e-5a75ce239658 |
| [/admin-portal/roles/01a13895-5cd2-731b-8dc0-f8a1b39395e8](http://localhost:3000/admin-portal/roles/01a13895-5cd2-731b-8dc0-f8a1b39395e8) | 200 | /admin-portal/roles/01a13895-5cd2-731b-8dc0-f8a1b39395e8 |
| [/admin-portal/roles/01a13895-5e90-7263-9767-20181c90e18b](http://localhost:3000/admin-portal/roles/01a13895-5e90-7263-9767-20181c90e18b) | 200 | /admin-portal/roles/01a13895-5e90-7263-9767-20181c90e18b |
| [/admin-portal/roles/01a13895-6083-73f1-9daf-699892282141](http://localhost:3000/admin-portal/roles/01a13895-6083-73f1-9daf-699892282141) | 200 | /admin-portal/roles/01a13895-6083-73f1-9daf-699892282141 |
| [/admin-portal/roles/01a13895-624f-73f9-a710-9cca745667d8](http://localhost:3000/admin-portal/roles/01a13895-624f-73f9-a710-9cca745667d8) | 200 | /admin-portal/roles/01a13895-624f-73f9-a710-9cca745667d8 |
| [/admin-portal/roles/01a13895-63f5-71c0-977a-610e342e334f](http://localhost:3000/admin-portal/roles/01a13895-63f5-71c0-977a-610e342e334f) | 200 | /admin-portal/roles/01a13895-63f5-71c0-977a-610e342e334f |
| [/admin-portal/roles/01a13895-65b3-7066-8c36-07addb76a0fa](http://localhost:3000/admin-portal/roles/01a13895-65b3-7066-8c36-07addb76a0fa) | 200 | /admin-portal/roles/01a13895-65b3-7066-8c36-07addb76a0fa |
| [/admin-portal/roles/01a13895-6741-7247-815c-2009bcc5e651](http://localhost:3000/admin-portal/roles/01a13895-6741-7247-815c-2009bcc5e651) | 200 | /admin-portal/roles/01a13895-6741-7247-815c-2009bcc5e651 |
| [/admin-portal/roles/01a13895-68cd-72bb-a027-aa15fc7f9229](http://localhost:3000/admin-portal/roles/01a13895-68cd-72bb-a027-aa15fc7f9229) | 200 | /admin-portal/roles/01a13895-68cd-72bb-a027-aa15fc7f9229 |
| [/admin-portal/roles/01a13895-6aa5-725c-a99a-ca9a13e77253](http://localhost:3000/admin-portal/roles/01a13895-6aa5-725c-a99a-ca9a13e77253) | 200 | /admin-portal/roles/01a13895-6aa5-725c-a99a-ca9a13e77253 |
| [/admin-portal/roles/01a13895-6e2d-7276-86ff-c644779f596c](http://localhost:3000/admin-portal/roles/01a13895-6e2d-7276-86ff-c644779f596c) | 200 | /admin-portal/roles/01a13895-6e2d-7276-86ff-c644779f596c |
| [/admin-portal/roles/01a13895-70ce-701c-97b9-bffa36932443](http://localhost:3000/admin-portal/roles/01a13895-70ce-701c-97b9-bffa36932443) | 200 | /admin-portal/roles/01a13895-70ce-701c-97b9-bffa36932443 |
| [/admin-portal/roles/01a13895-8ea4-7122-9c3a-89e4eff4b764](http://localhost:3000/admin-portal/roles/01a13895-8ea4-7122-9c3a-89e4eff4b764) | 200 | /admin-portal/roles/01a13895-8ea4-7122-9c3a-89e4eff4b764 |
| [/admin-portal/roles/01a13898-58f6-708d-bc10-d45ae9474b99](http://localhost:3000/admin-portal/roles/01a13898-58f6-708d-bc10-d45ae9474b99) | 200 | /admin-portal/roles/01a13898-58f6-708d-bc10-d45ae9474b99 |
| [/admin-portal/roles/01a13898-5add-709c-83e4-dcc0f84c5220](http://localhost:3000/admin-portal/roles/01a13898-5add-709c-83e4-dcc0f84c5220) | 200 | /admin-portal/roles/01a13898-5add-709c-83e4-dcc0f84c5220 |
| [/admin-portal/roles/01a13898-5c7f-7385-b519-ba18f0c3e430](http://localhost:3000/admin-portal/roles/01a13898-5c7f-7385-b519-ba18f0c3e430) | 200 | /admin-portal/roles/01a13898-5c7f-7385-b519-ba18f0c3e430 |
| [/admin-portal/roles/01a13898-5e0b-734f-acdd-b0df2c70b835](http://localhost:3000/admin-portal/roles/01a13898-5e0b-734f-acdd-b0df2c70b835) | 200 | /admin-portal/roles/01a13898-5e0b-734f-acdd-b0df2c70b835 |
| [/admin-portal/roles/01a13898-5fb0-7205-920d-3ae0b5131f4b](http://localhost:3000/admin-portal/roles/01a13898-5fb0-7205-920d-3ae0b5131f4b) | 200 | /admin-portal/roles/01a13898-5fb0-7205-920d-3ae0b5131f4b |
| [/admin-portal/roles/01a13898-6151-7029-bfa1-4657464a36e8](http://localhost:3000/admin-portal/roles/01a13898-6151-7029-bfa1-4657464a36e8) | 200 | /admin-portal/roles/01a13898-6151-7029-bfa1-4657464a36e8 |
| [/admin-portal/roles/01a13898-62f9-70ab-91ef-fe1e25a9dfc9](http://localhost:3000/admin-portal/roles/01a13898-62f9-70ab-91ef-fe1e25a9dfc9) | 200 | /admin-portal/roles/01a13898-62f9-70ab-91ef-fe1e25a9dfc9 |
| [/admin-portal/roles/01a13898-6511-72d3-aa3c-e4a2ac89907d](http://localhost:3000/admin-portal/roles/01a13898-6511-72d3-aa3c-e4a2ac89907d) | 200 | /admin-portal/roles/01a13898-6511-72d3-aa3c-e4a2ac89907d |
| [/admin-portal/roles/01a13898-673c-7257-b017-4fb43a83d8cb](http://localhost:3000/admin-portal/roles/01a13898-673c-7257-b017-4fb43a83d8cb) | 200 | /admin-portal/roles/01a13898-673c-7257-b017-4fb43a83d8cb |
| [/admin-portal/roles/01a13898-699e-72fa-98a1-99890a1ad356](http://localhost:3000/admin-portal/roles/01a13898-699e-72fa-98a1-99890a1ad356) | 200 | /admin-portal/roles/01a13898-699e-72fa-98a1-99890a1ad356 |
| [/admin-portal/roles/01a13898-6ba9-70c8-8830-a07b55ad7aa5](http://localhost:3000/admin-portal/roles/01a13898-6ba9-70c8-8830-a07b55ad7aa5) | 200 | /admin-portal/roles/01a13898-6ba9-70c8-8830-a07b55ad7aa5 |
| [/admin-portal/roles/01a13898-6db0-71e3-b67c-deff895b7c67](http://localhost:3000/admin-portal/roles/01a13898-6db0-71e3-b67c-deff895b7c67) | 200 | /admin-portal/roles/01a13898-6db0-71e3-b67c-deff895b7c67 |
| [/admin-portal/roles/01a13898-6fb8-73af-aa13-c7a7a9f3ae79](http://localhost:3000/admin-portal/roles/01a13898-6fb8-73af-aa13-c7a7a9f3ae79) | 200 | /admin-portal/roles/01a13898-6fb8-73af-aa13-c7a7a9f3ae79 |
| [/admin-portal/roles/01a13898-7233-734f-be7e-1bfc1466a7a2](http://localhost:3000/admin-portal/roles/01a13898-7233-734f-be7e-1bfc1466a7a2) | 200 | /admin-portal/roles/01a13898-7233-734f-be7e-1bfc1466a7a2 |
| [/admin-portal/roles/01a13898-74ec-737d-9639-3d5df611459d](http://localhost:3000/admin-portal/roles/01a13898-74ec-737d-9639-3d5df611459d) | 200 | /admin-portal/roles/01a13898-74ec-737d-9639-3d5df611459d |
| [/admin-portal/roles/01a13898-774e-7183-9892-bd0102c2d4ef](http://localhost:3000/admin-portal/roles/01a13898-774e-7183-9892-bd0102c2d4ef) | 200 | /admin-portal/roles/01a13898-774e-7183-9892-bd0102c2d4ef |
| [/admin-portal/transactions](http://localhost:3000/admin-portal/transactions) | 200 | /admin-portal/transactions |
| [/admin-portal/transactions/01a150d2-fece-7378-8bc6-350138408552](http://localhost:3000/admin-portal/transactions/01a150d2-fece-7378-8bc6-350138408552) | 200 | /admin-portal/transactions/01a150d2-fece-7378-8bc6-350138408552 |
| [/admin-portal/transactions/01a150e8-b79b-71d4-8597-fef314d36fae](http://localhost:3000/admin-portal/transactions/01a150e8-b79b-71d4-8597-fef314d36fae) | 200 | /admin-portal/transactions/01a150e8-b79b-71d4-8597-fef314d36fae |
| [/admin-portal/transactions/01a150ef-78f9-7250-863a-c88213986f9b](http://localhost:3000/admin-portal/transactions/01a150ef-78f9-7250-863a-c88213986f9b) | 200 | /admin-portal/transactions/01a150ef-78f9-7250-863a-c88213986f9b |
| [/admin-portal/transactions/01a150ef-c3d0-71af-be0e-49f353ba6d3b](http://localhost:3000/admin-portal/transactions/01a150ef-c3d0-71af-be0e-49f353ba6d3b) | 200 | /admin-portal/transactions/01a150ef-c3d0-71af-be0e-49f353ba6d3b |
| [/admin-portal/transactions/01a1528b-f492-72d8-822b-1561dc429ab9](http://localhost:3000/admin-portal/transactions/01a1528b-f492-72d8-822b-1561dc429ab9) | 200 | /admin-portal/transactions/01a1528b-f492-72d8-822b-1561dc429ab9 |
| [/admin-portal/transactions/01a1528c-be68-71a9-8aa2-03d15a8a5357](http://localhost:3000/admin-portal/transactions/01a1528c-be68-71a9-8aa2-03d15a8a5357) | 200 | /admin-portal/transactions/01a1528c-be68-71a9-8aa2-03d15a8a5357 |
| [/admin-portal/transactions/06bc3eb9-9a58-55f7-bfbb-83579e072fb5](http://localhost:3000/admin-portal/transactions/06bc3eb9-9a58-55f7-bfbb-83579e072fb5) | 200 | /admin-portal/transactions/06bc3eb9-9a58-55f7-bfbb-83579e072fb5 |
| [/admin-portal/transactions/085b5822-421d-53ac-b817-e02a5754e674](http://localhost:3000/admin-portal/transactions/085b5822-421d-53ac-b817-e02a5754e674) | 200 | /admin-portal/transactions/085b5822-421d-53ac-b817-e02a5754e674 |
| [/admin-portal/transactions/2c00f008-d0f3-5079-a2f8-c7d98403fe0b](http://localhost:3000/admin-portal/transactions/2c00f008-d0f3-5079-a2f8-c7d98403fe0b) | 200 | /admin-portal/transactions/2c00f008-d0f3-5079-a2f8-c7d98403fe0b |
| [/admin-portal/transactions/33b2b513-e19e-5b00-a147-891b6b185e82](http://localhost:3000/admin-portal/transactions/33b2b513-e19e-5b00-a147-891b6b185e82) | 200 | /admin-portal/transactions/33b2b513-e19e-5b00-a147-891b6b185e82 |
| [/admin-portal/transactions/461f2dbf-98c1-5f56-8b0d-8ae4c8f6cbfa](http://localhost:3000/admin-portal/transactions/461f2dbf-98c1-5f56-8b0d-8ae4c8f6cbfa) | 200 | /admin-portal/transactions/461f2dbf-98c1-5f56-8b0d-8ae4c8f6cbfa |
| [/admin-portal/transactions/63b2b9e0-ddff-5c95-a5e8-66678c58e36e](http://localhost:3000/admin-portal/transactions/63b2b9e0-ddff-5c95-a5e8-66678c58e36e) | 200 | /admin-portal/transactions/63b2b9e0-ddff-5c95-a5e8-66678c58e36e |
| [/admin-portal/transactions/a3039ae3-c7cd-4cc0-bbe1-a5ca1a9515bc](http://localhost:3000/admin-portal/transactions/a3039ae3-c7cd-4cc0-bbe1-a5ca1a9515bc) | 200 | /admin-portal/transactions/a3039ae3-c7cd-4cc0-bbe1-a5ca1a9515bc |
| [/admin-portal/transactions/a3039da6-3a08-4cf3-8928-2f11cc0d7390](http://localhost:3000/admin-portal/transactions/a3039da6-3a08-4cf3-8928-2f11cc0d7390) | 200 | /admin-portal/transactions/a3039da6-3a08-4cf3-8928-2f11cc0d7390 |
| [/admin-portal/transactions/a3043bce-fc58-4fb3-a96c-e258432a8f03](http://localhost:3000/admin-portal/transactions/a3043bce-fc58-4fb3-a96c-e258432a8f03) | 200 | /admin-portal/transactions/a3043bce-fc58-4fb3-a96c-e258432a8f03 |
| [/admin-portal/transactions/bbe9664a-2031-55c4-a496-da478a91202d](http://localhost:3000/admin-portal/transactions/bbe9664a-2031-55c4-a496-da478a91202d) | 200 | /admin-portal/transactions/bbe9664a-2031-55c4-a496-da478a91202d |
| [/admin-portal/users](http://localhost:3000/admin-portal/users) | 200 | /admin-portal/users |
| [/admin-portal/users/01a11b20-5132-7162-8696-ff5cd9cdc1ab](http://localhost:3000/admin-portal/users/01a11b20-5132-7162-8696-ff5cd9cdc1ab) | 200 | /admin-portal/users/01a11b20-5132-7162-8696-ff5cd9cdc1ab |
| [/admin-portal/users/01a11b20-5241-729a-b371-7ca6bf86d265](http://localhost:3000/admin-portal/users/01a11b20-5241-729a-b371-7ca6bf86d265) | 200 | /admin-portal/users/01a11b20-5241-729a-b371-7ca6bf86d265 |
| [/admin-portal/users/594dde0f-8ef6-4b11-b61b-5ea86d313db5](http://localhost:3000/admin-portal/users/594dde0f-8ef6-4b11-b61b-5ea86d313db5) | 200 | /admin-portal/users/594dde0f-8ef6-4b11-b61b-5ea86d313db5 |
| [/admin-portal/users/a2f26ca6-f488-4eb8-a524-6d018c014f09](http://localhost:3000/admin-portal/users/a2f26ca6-f488-4eb8-a524-6d018c014f09) | 200 | /admin-portal/users/a2f26ca6-f488-4eb8-a524-6d018c014f09 |
| [/admin-portal/users/a2f26ce8-60ba-4ea4-bfad-8a7091c00c7a](http://localhost:3000/admin-portal/users/a2f26ce8-60ba-4ea4-bfad-8a7091c00c7a) | 200 | /admin-portal/users/a2f26ce8-60ba-4ea4-bfad-8a7091c00c7a |
| [/admin-portal/users/a303968d-5035-40ab-8d86-d3de313c2a95](http://localhost:3000/admin-portal/users/a303968d-5035-40ab-8d86-d3de313c2a95) | 200 | /admin-portal/users/a303968d-5035-40ab-8d86-d3de313c2a95 |
| [/admin-portal/wallets](http://localhost:3000/admin-portal/wallets) | 200 | /admin-portal/wallets |
| [/admin-portal/wallets/01a150c8-101a-7184-81c1-8d17774ff7bd](http://localhost:3000/admin-portal/wallets/01a150c8-101a-7184-81c1-8d17774ff7bd) | 200 | /admin-portal/wallets/01a150c8-101a-7184-81c1-8d17774ff7bd |
| [/admin-portal/wallets/01a150c8-15b2-71d1-8abf-909b08ebd490](http://localhost:3000/admin-portal/wallets/01a150c8-15b2-71d1-8abf-909b08ebd490) | 200 | /admin-portal/wallets/01a150c8-15b2-71d1-8abf-909b08ebd490 |
| [/admin-portal/wallets/01a150dd-955d-7316-bf9e-88f467fd7543](http://localhost:3000/admin-portal/wallets/01a150dd-955d-7316-bf9e-88f467fd7543) | 200 | /admin-portal/wallets/01a150dd-955d-7316-bf9e-88f467fd7543 |
| [/admin-portal/wallets/fe1d5886-4d8f-4d70-af9d-81772be07718](http://localhost:3000/admin-portal/wallets/fe1d5886-4d8f-4d70-af9d-81772be07718) | 200 | /admin-portal/wallets/fe1d5886-4d8f-4d70-af9d-81772be07718 |
| [/agents](http://localhost:3000/agents) | 200 | /agents |
| [/agents/4196cf5c-871c-4a1f-8526-a72e906d3106](http://localhost:3000/agents/4196cf5c-871c-4a1f-8526-a72e906d3106) | 200 | /agents/4196cf5c-871c-4a1f-8526-a72e906d3106 |
| [/audit](http://localhost:3000/audit) | 200 | /audit |
| [/community/approvals](http://localhost:3000/community/approvals) | 200 | /community/approvals |
| [/community/campaigns](http://localhost:3000/community/campaigns) | 200 | /community/campaigns |
| [/community/campaigns/new](http://localhost:3000/community/campaigns/new) | 200 | /community/campaigns/new |
| [/community/consent](http://localhost:3000/community/consent) | 200 | /community/consent |
| [/community/exports](http://localhost:3000/community/exports) | 200 | /community/exports |
| [/community/orders](http://localhost:3000/community/orders) | 200 | /community/orders |
| [/community/organisations](http://localhost:3000/community/organisations) | 200 | /community/organisations |
| [/community/partners](http://localhost:3000/community/partners) | 200 | /community/partners |
| [/community/refunds](http://localhost:3000/community/refunds) | 200 | /community/refunds |
| [/community/service-entitlements](http://localhost:3000/community/service-entitlements) | 200 | /community/service-entitlements |
| [/compliance](http://localhost:3000/compliance) | 200 | /compliance |
| [/compliance/retention](http://localhost:3000/compliance/retention) | 200 | /compliance/retention |
| [/configuration](http://localhost:3000/configuration) | 200 | /configuration |
| [/configuration/approvals](http://localhost:3000/configuration/approvals) | 200 | /configuration/approvals |
| [/configuration/approvals/01a15289-e793-7382-bff8-fd21c537fb20](http://localhost:3000/configuration/approvals/01a15289-e793-7382-bff8-fd21c537fb20) | 200 | /configuration/approvals/01a15289-e793-7382-bff8-fd21c537fb20 |
| [/configuration/auth/auth.biometric.enabled](http://localhost:3000/configuration/auth/auth.biometric.enabled) | 200 | /configuration/auth/auth.biometric.enabled |
| [/configuration/auth/auth.lockout.initial_duration](http://localhost:3000/configuration/auth/auth.lockout.initial_duration) | 200 | /configuration/auth/auth.lockout.initial_duration |
| [/configuration/auth/auth.lockout.max_failed_attempts](http://localhost:3000/configuration/auth/auth.lockout.max_failed_attempts) | 200 | /configuration/auth/auth.lockout.max_failed_attempts |
| [/configuration/auth/auth.lockout.progressive_enabled](http://localhost:3000/configuration/auth/auth.lockout.progressive_enabled) | 200 | /configuration/auth/auth.lockout.progressive_enabled |
| [/configuration/auth/auth.mfa.admin.fido2_enabled](http://localhost:3000/configuration/auth/auth.mfa.admin.fido2_enabled) | 200 | /configuration/auth/auth.mfa.admin.fido2_enabled |
| [/configuration/auth/auth.mfa.admin.sms_enabled](http://localhost:3000/configuration/auth/auth.mfa.admin.sms_enabled) | 200 | /configuration/auth/auth.mfa.admin.sms_enabled |
| [/configuration/auth/auth.mfa.admin.totp_enabled](http://localhost:3000/configuration/auth/auth.mfa.admin.totp_enabled) | 200 | /configuration/auth/auth.mfa.admin.totp_enabled |
| [/configuration/auth/auth.otp.max_attempts](http://localhost:3000/configuration/auth/auth.otp.max_attempts) | 200 | /configuration/auth/auth.otp.max_attempts |
| [/configuration/auth/auth.otp.max_sends_per_hour](http://localhost:3000/configuration/auth/auth.otp.max_sends_per_hour) | 200 | /configuration/auth/auth.otp.max_sends_per_hour |
| [/configuration/auth/auth.otp.provider](http://localhost:3000/configuration/auth/auth.otp.provider) | 200 | /configuration/auth/auth.otp.provider |
| [/configuration/auth/auth.otp.resend_cooldown](http://localhost:3000/configuration/auth/auth.otp.resend_cooldown) | 200 | /configuration/auth/auth.otp.resend_cooldown |
| [/configuration/auth/auth.otp.ttl](http://localhost:3000/configuration/auth/auth.otp.ttl) | 200 | /configuration/auth/auth.otp.ttl |
| [/configuration/auth/auth.permission_cache_ttl](http://localhost:3000/configuration/auth/auth.permission_cache_ttl) | 200 | /configuration/auth/auth.permission_cache_ttl |
| [/configuration/auth/auth.pin.length](http://localhost:3000/configuration/auth/auth.pin.length) | 200 | /configuration/auth/auth.pin.length |
| [/configuration/auth/auth.pin.reject_common](http://localhost:3000/configuration/auth/auth.pin.reject_common) | 200 | /configuration/auth/auth.pin.reject_common |
| [/configuration/auth/auth.pin.reject_repeated](http://localhost:3000/configuration/auth/auth.pin.reject_repeated) | 200 | /configuration/auth/auth.pin.reject_repeated |
| [/configuration/auth/auth.pin.reject_sequential](http://localhost:3000/configuration/auth/auth.pin.reject_sequential) | 200 | /configuration/auth/auth.pin.reject_sequential |
| [/configuration/auth/auth.refresh_token.reuse_detection_enabled](http://localhost:3000/configuration/auth/auth.refresh_token.reuse_detection_enabled) | 200 | /configuration/auth/auth.refresh_token.reuse_detection_enabled |
| [/configuration/auth/auth.refresh_token.rotation_enabled](http://localhost:3000/configuration/auth/auth.refresh_token.rotation_enabled) | 200 | /configuration/auth/auth.refresh_token.rotation_enabled |
| [/configuration/auth/auth.refresh_token.ttl](http://localhost:3000/configuration/auth/auth.refresh_token.ttl) | 200 | /configuration/auth/auth.refresh_token.ttl |
| [/dashboard](http://localhost:3000/dashboard) | 200 | /dashboard |
| [/devices](http://localhost:3000/devices) | 200 | /devices |
| [/fees/rules](http://localhost:3000/fees/rules) | 200 | /fees/rules |
| [/fees/rules/create](http://localhost:3000/fees/rules/create) | 200 | /fees/rules/create |
| [/financial-dashboard](http://localhost:3000/financial-dashboard) | 200 | /financial-dashboard |
| [/forgot-password](http://localhost:3000/forgot-password) | 200 | /forgot-password |
| [/integrations](http://localhost:3000/integrations) | 200 | /integrations |
| [/integrations/clients](http://localhost:3000/integrations/clients) | 200 | /integrations/clients |
| [/integrations/providers](http://localhost:3000/integrations/providers) | 200 | /integrations/providers |
| [/integrations/providers/f61d8c33-8216-53c2-a45c-eb4aec9fc185/health](http://localhost:3000/integrations/providers/f61d8c33-8216-53c2-a45c-eb4aec9fc185/health) | 200 | /integrations/providers/f61d8c33-8216-53c2-a45c-eb4aec9fc185/health |
| [/integrations/webhooks](http://localhost:3000/integrations/webhooks) | 200 | /integrations/webhooks |
| [/kyc](http://localhost:3000/kyc) | 200 | /kyc |
| [/kyc/460661f1-e0c2-58cb-a56c-278f4e40c889](http://localhost:3000/kyc/460661f1-e0c2-58cb-a56c-278f4e40c889) | 200 | /kyc/460661f1-e0c2-58cb-a56c-278f4e40c889 |
| [/login](http://localhost:3000/login) | 200 | /dashboard |
| [/maintenance](http://localhost:3000/maintenance) | 200 | /maintenance |
| [/mfa-enrollment](http://localhost:3000/mfa-enrollment) | 200 | /mfa-enrollment |
| [/multi-currency/currencies](http://localhost:3000/multi-currency/currencies) | 200 | /multi-currency/currencies |
| [/multi-currency/fx-rates](http://localhost:3000/multi-currency/fx-rates) | 200 | /multi-currency/fx-rates |
| [/notifications](http://localhost:3000/notifications) | 200 | /notifications |
| [/notifications/log](http://localhost:3000/notifications/log) | 200 | /notifications/log |
| [/notifications/rules](http://localhost:3000/notifications/rules) | 200 | /notifications/rules |
| [/notifications/templates](http://localhost:3000/notifications/templates) | 200 | /notifications/templates |
| [/offline](http://localhost:3000/offline) | 200 | /offline |
| [/operations/dead-letter](http://localhost:3000/operations/dead-letter) | 200 | /operations/dead-letter |
| [/ops](http://localhost:3000/ops) | 200 | /ops |
| [/ops/alert-rules](http://localhost:3000/ops/alert-rules) | 200 | /ops/alert-rules |
| [/ops/alerts](http://localhost:3000/ops/alerts) | 200 | /ops/alerts |
| [/ops/backup-jobs](http://localhost:3000/ops/backup-jobs) | 200 | /ops/backup-jobs |
| [/ops/db-monitoring](http://localhost:3000/ops/db-monitoring) | 200 | /ops/db-monitoring |
| [/ops/deployments](http://localhost:3000/ops/deployments) | 200 | /ops/deployments |
| [/ops/logs](http://localhost:3000/ops/logs) | 200 | /ops/logs |
| [/ops/metrics](http://localhost:3000/ops/metrics) | 200 | /ops/metrics |
| [/ops/providers](http://localhost:3000/ops/providers) | 200 | /ops/providers |
| [/ops/queue-monitoring](http://localhost:3000/ops/queue-monitoring) | 200 | /ops/queue-monitoring |
| [/payment/providers](http://localhost:3000/payment/providers) | 200 | /payment/providers |
| [/payment/providers/create](http://localhost:3000/payment/providers/create) | 200 | /payment/providers/create |
| [/payment/reconciliation/exceptions](http://localhost:3000/payment/reconciliation/exceptions) | 200 | /payment/reconciliation/exceptions |
| [/platform/security-config](http://localhost:3000/platform/security-config) | 200 | /platform/security-config |
| [/profile](http://localhost:3000/profile) | 200 | /profile |
| [/profile/notifications](http://localhost:3000/profile/notifications) | 200 | /profile/notifications |
| [/profile/security](http://localhost:3000/profile/security) | 200 | /profile/security |
| [/profile/sessions](http://localhost:3000/profile/sessions) | 200 | /profile/sessions |
| [/qa](http://localhost:3000/qa) | 200 | /qa |
| [/reconciliation](http://localhost:3000/reconciliation) | 200 | /reconciliation |
| [/reconciliation/batches](http://localhost:3000/reconciliation/batches) | 200 | /reconciliation/batches |
| [/reconciliation/batches/10042cc2-9d1d-598e-919d-6924562d9db1](http://localhost:3000/reconciliation/batches/10042cc2-9d1d-598e-919d-6924562d9db1) | 200 | /reconciliation/batches/10042cc2-9d1d-598e-919d-6924562d9db1 |
| [/reconciliation/settlements](http://localhost:3000/reconciliation/settlements) | 200 | /reconciliation/settlements |
| [/reconciliation/settlements/e807c4bd-9d38-5665-9cad-ebe1d95d8216](http://localhost:3000/reconciliation/settlements/e807c4bd-9d38-5665-9cad-ebe1d95d8216) | 200 | /reconciliation/settlements/e807c4bd-9d38-5665-9cad-ebe1d95d8216 |
| [/reports](http://localhost:3000/reports) | 200 | /reports |
| [/reports/catalogue](http://localhost:3000/reports/catalogue) | 200 | /reports/catalogue |
| [/reports/reconciliation](http://localhost:3000/reports/reconciliation) | 200 | /reports/reconciliation |
| [/reports/schedules](http://localhost:3000/reports/schedules) | 200 | /reports/schedules |
| [/reports/settlement](http://localhost:3000/reports/settlement) | 200 | /reports/settlement |
| [/reset-password](http://localhost:3000/reset-password) | 200 | /reset-password |
| [/risk-dashboard](http://localhost:3000/risk-dashboard) | 200 | /risk-dashboard |
| [/security/alerts/90443150-ed98-5d12-8ad0-881b65c97328](http://localhost:3000/security/alerts/90443150-ed98-5d12-8ad0-881b65c97328) | 200 | /security/alerts/90443150-ed98-5d12-8ad0-881b65c97328 |
| [/security/dashboard](http://localhost:3000/security/dashboard) | 200 | /security/dashboard |
| [/security/events](http://localhost:3000/security/events) | 200 | /security/events |
| [/security/findings](http://localhost:3000/security/findings) | 200 | /security/findings |
| [/security/findings/5f0fe20c-429f-5224-b0cb-0bf4a39b4bef](http://localhost:3000/security/findings/5f0fe20c-429f-5224-b0cb-0bf4a39b4bef) | 200 | /security/findings/5f0fe20c-429f-5224-b0cb-0bf4a39b4bef |
| [/security/overview](http://localhost:3000/security/overview) | 200 | /security/overview |
| [/sessions](http://localhost:3000/sessions) | 200 | /sessions |
| [/support/cases](http://localhost:3000/support/cases) | 200 | /support/cases |
| [/support/cases/f0877561-1dd2-5bb4-96e7-c5f6702709f0](http://localhost:3000/support/cases/f0877561-1dd2-5bb4-96e7-c5f6702709f0) | 200 | /support/cases/f0877561-1dd2-5bb4-96e7-c5f6702709f0 |
| [/unsupported-browser](http://localhost:3000/unsupported-browser) | 200 | /unsupported-browser |
| [/verify-email](http://localhost:3000/verify-email) | 200 | /verify-email |
