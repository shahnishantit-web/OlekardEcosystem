# Development dummy banks — 2026-10-26

User request: add bank1, bank2 and bank3 to `/payments/banks`.

Implemented and seeded locally through `Database\Seeders\DevDummyBankSeeder`:
codes bank1/bank2/bank3, names Bank1/Bank2/Bank3, environment mock, draft lifecycle,
disabled and explicitly labelled development/test only. No accounts, URLs,
credentials, certification evidence or financial data created. Seeder is
idempotent, preserves existing configuration and refuses non-development
environments. Live callback processing remains false.

Replaced the bank-list loading placeholder with an authenticated API table,
working status filters, pagination, metadata links and explicit errors/empty
states. The existing payment.configuration permission is enforced; verified the
local admin role already holds it. No permission changes made. Fixed the existing
bank API client's unsupported params/headers options to match its client contract.

Local setup exposed an unapplied bank metadata migration and unsupported
PostgreSQL ADD CONSTRAINT IF NOT EXISTS syntax. Corrected that migration's
constraint guard and applied only
2026_10_24_000001_add_bank_metadata_to_providers.php; no blanket migration/reseed.

Verification: DevDummyBankSeederTest 3 pass / 20 assertions: API list, repeated
seed, disabled/draft/account-free state and production refusal. BankList tests
2 pass: API-backed rows/links/test labels, filtering and errors. Broader existing
P12 component suite plus new tests: 14 pass / 1 fail; the existing
ApiOperationEditor selector test ambiguously selects two Select options. Full
TypeScript check has four unrelated stale .next/types errors for removed
admin-portal role pages; bank-module type errors fixed. See dummy-banks-types.log.

Rerun seed from services/api with `php artisan db:seed --class=DevDummyBankSeeder`.
Read-only verification: `php specs/061-bank-integration-config/verify-dummy-banks.php`
from repository root. These are display placeholders; activation/onboarding and
existing bank detail/account editor scaffolds are outside this small change.
