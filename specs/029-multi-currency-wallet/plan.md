# Implementation Plan — E29 Multi-Currency Wallet

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected
- [x] No TBD value invented (FX provider remains TBD per spec; not resolved here)
- [x] Authorization remains server-side (`multi_currency.*` permissions via E04)
- [x] Financial integrity: double-entry FX journal, idempotency on create-wallet + transfer + quote-confirm
- [x] Security/audit: AUDIT_EVENT_RECORDED per story; correlation IDs on all endpoints
- [x] UX/design-system: mobile wallet screens via Flutter; admin rate management via Next.js admin portal
- [x] QA strategy: unit, integration, API, concurrency, idempotency tests per story

## Technical Context

- **Backend:** Laravel / PHP 8.4+
- **Database:** PostgreSQL — new schema `multi_currency`
- **Queue/Cache:** Redis + Laravel Horizon (quote TTL expiry via scheduled cleanup)
- **Web Admin:** Next.js + TypeScript (FX rate management, currency activation pages)
- **Mobile:** Flutter (currency wallet list, balance view, conversion flow, transfer flow)
- **API:** REST/OpenAPI — see `contracts/openapi.yaml`
- **Testing:** PHPUnit/Pest for PHP; Flutter tests for mobile; Playwright/Cypress for admin web
- **Observability:** structured logs, correlation IDs, `US_MCW_*` domain events, audit hook

## Upstream Dependencies

| Module | Integration Point |
|---|---|
| E06 Wallet | `WalletServiceInterface::create()` — provision underlying wallet per currency |
| E07 Ledger | `LedgerService::post()` — FX journal entries (suspense account pattern) |
| E28 ConfigurationManagement | FX rate change-request + maker-checker approval |
| E21 FeesPricing | Retrieve FX spread/fee rate by currency pair |
| E04 Authorization | `multi_currency.create_wallet`, `multi_currency.transfer`, `multi_currency.convert`, `multi_currency.configure_rate` |
| E01 Identity | Resolve `customer_id` from bearer token |

## Module Structure

```
app/Modules/MultiCurrency/
├── Application/
│   ├── DTOs/
│   │   ├── CreateCurrencyWalletDTO.php
│   │   ├── FxQuoteRequestDTO.php
│   │   ├── FxQuoteConfirmDTO.php
│   │   ├── SameCurrencyTransferDTO.php
│   │   └── FxFeePreviewDTO.php
│   └── Services/
│       ├── CurrencyWalletService.php       (US-MCW-001, 002, 003)
│       ├── FxRateAdminService.php          (US-MCW-005)
│       ├── FxQuoteService.php              (US-MCW-006, 007 Phase 1)
│       ├── FxConversionService.php         (US-MCW-007 Phase 2, US-MCW-008)
│       ├── SameCurrencyTransferService.php (US-MCW-004)
│       └── FxTransactionHistoryService.php (US-MCW-009)
├── Domain/
│   ├── Contracts/
│   │   ├── CurrencyRepositoryInterface.php
│   │   ├── CurrencyWalletRepositoryInterface.php
│   │   ├── FxRateRepositoryInterface.php
│   │   ├── FxQuoteRepositoryInterface.php
│   │   ├── FxTransactionRepositoryInterface.php
│   │   └── SameCurrencyTransferRepositoryInterface.php
│   ├── Enums/
│   │   ├── FxQuoteStatus.php              (quoted|confirmed|expired|cancelled)
│   │   └── FxTransactionStatus.php        (completed|failed)
│   └── Events/
│       ├── CurrencyWalletCreatedEvent.php
│       ├── FxQuoteIssuedEvent.php
│       ├── FxConversionCompletedEvent.php
│       ├── FxConversionFailedEvent.php
│       └── SameCurrencyTransferCompletedEvent.php
├── Http/
│   ├── Controllers/
│   │   ├── CurrencyController.php          (GET /currencies)
│   │   ├── CurrencyWalletController.php    (CRUD /wallets, POST /wallets/{id}/transfers)
│   │   ├── FxQuoteController.php           (POST /fx-quotes, POST /fx-quotes/{id}/confirm)
│   │   ├── FxTransactionController.php     (GET /fx-transactions)
│   │   ├── FxFeeController.php             (GET /fx-fee)
│   │   └── Admin/
│   │       ├── AdminCurrencyController.php
│   │       └── AdminFxRateController.php
│   ├── Requests/
│   │   ├── CreateCurrencyWalletRequest.php
│   │   ├── FxQuoteRequest.php
│   │   ├── FxQuoteConfirmRequest.php
│   │   └── SameCurrencyTransferRequest.php
│   └── Resources/
│       ├── CurrencyResource.php
│       ├── CurrencyWalletResource.php
│       ├── FxQuoteResource.php
│       ├── FxTransactionResource.php
│       └── FxRateResource.php
├── Infrastructure/
│   ├── Persistence/
│   │   ├── CurrencyModel.php
│   │   ├── CurrencyWalletModel.php
│   │   ├── FxRateModel.php
│   │   ├── FxQuoteModel.php
│   │   ├── FxTransactionModel.php
│   │   └── SameCurrencyTransferModel.php
│   └── Repositories/
│       ├── EloquentCurrencyRepository.php
│       ├── EloquentCurrencyWalletRepository.php
│       ├── EloquentFxRateRepository.php
│       ├── EloquentFxQuoteRepository.php
│       ├── EloquentFxTransactionRepository.php
│       └── EloquentSameCurrencyTransferRepository.php
└── MultiCurrencyServiceProvider.php
```

**Routes:** `routes/api_multi_currency.php` (customer) + `routes/api_admin_multi_currency.php` (admin)

**Migrations:** `database/migrations/multi_currency/`
- `001_create_multi_currency_schema.php`
- `002_create_currencies_table.php`
- `003_create_currency_wallets_table.php`
- `004_create_fx_rates_table.php`
- `005_create_fx_quotes_table.php`
- `006_create_fx_transactions_table.php`
- `007_create_same_currency_transfers_table.php`
- `008_seed_initial_currencies_and_rates.php`

**Admin portal pages (Next.js):**
- `app/(admin)/multi-currency/currencies/page.tsx` — currency list + activate/deactivate
- `app/(admin)/multi-currency/fx-rates/page.tsx` — rate list + submit change request
- `app/(admin)/multi-currency/fx-rates/[id]/page.tsx` — rate change request detail

**Mobile screens (Flutter):**
- `lib/features/multi_currency/screens/` — wallet list, wallet detail, convert flow, transfer flow

## Financial Controls

- **Double-entry FX journal:** 3-entry pattern (Dr source, Dr/Cr suspense, Cr destination, Cr fee revenue) posted atomically via E07 `LedgerService`.
- **Idempotency:** `create wallet` (idempotency_key), `transfer` (idempotency_key), `quote-confirm` (idempotency_key on quote; second confirm with same key returns existing FxTransaction).
- **Concurrency:** `lockForUpdate()` on CurrencyWallet row during transfer and conversion to prevent double-spend.
- **Quote TTL:** 60-second lock window; expired quotes return 422; lazy expiry on access.
- **FX suspense:** Virtual account seeded in E07 ledger; nets to zero per transaction — verified in reconciliation.

## New Permissions Required

| Slug | Description |
|---|---|
| `multi_currency.create_wallet` | Create a currency wallet |
| `multi_currency.view_balances` | View own currency wallet balances |
| `multi_currency.transfer` | Execute same-currency transfer |
| `multi_currency.convert` | Request FX quote and confirm conversion |
| `multi_currency.configure_rate` | Submit/approve FX rate changes (admin) |
| `multi_currency.manage_currencies` | Add/activate currencies (admin) |

## OpenAPI

`openapi/v1/multi_currency.yaml` — mirrors `contracts/openapi.yaml`

## Implementation Order

1. DB migrations + seeders
2. Domain enums, repository interfaces, Eloquent models
3. Repository implementations
4. `CurrencyWalletService` + `CurrencyController` (US-MCW-001, 002, 003)
5. `SameCurrencyTransferService` + transfer endpoint (US-MCW-004)
6. `FxQuoteService` + fee preview + quote endpoint (US-MCW-005, 006, 007 Phase 1)
7. `FxConversionService` + confirm endpoint + ledger posting (US-MCW-007 Phase 2, 008)
8. `FxTransactionHistoryService` + history endpoints (US-MCW-009)
9. Admin controllers: currency management + FX rate submission (US-MCW-002, 005)
10. Events, audit hooks, permissions seeder
11. Next.js admin pages + Flutter screens
12. Tests (unit, API, concurrency, idempotency)
13. OpenAPI docs + PHPStan level 8
