# Data Model: P20 — Admin Provider Configuration and Policy Screens

## Existing Tables (READ — no changes)

### `payment.payment_providers` (READ ONLY)

Used by Provider Registry list and detail screens. Key fields for P20 UI:

| Column | Type | P20 Usage |
|--------|------|-----------|
| `id` | UUID | PK / URL parameter |
| `code` | VARCHAR(64) | Display |
| `display_name` | VARCHAR(128) | Display |
| `adapter_identifier` | VARCHAR(128) | Edit form — restricted to allowlist |
| `adapter_version` | VARCHAR(32) | Display |
| `environment` | VARCHAR(32) | EnvironmentBadge source |
| `is_enabled` | BOOLEAN | ProviderStatusBadge |
| `is_suspended` | BOOLEAN | ProviderStatusBadge / lifecycle |
| `is_default` | BOOLEAN | Display |
| `sort_order` | INTEGER | Display / editable via existing PUT |
| `created_at`, `updated_at` | TIMESTAMPTZ | Display |

### `payment.payment_provider_accounts` (READ + WRITE via new endpoints)

Used by Account detail and config form screens. Key fields:

| Column | Type | P20 Usage |
|--------|------|-----------|
| `id` | UUID | PK / URL parameter |
| `provider_id` | UUID FK | Parent link |
| `merchant_account_reference` | VARCHAR | Edit form — HIGH RISK → draft |
| `supported_currencies` | JSONB array | Edit form |
| `supported_payment_methods` | JSONB array | Edit form |
| `min_amount_minor` | INTEGER | Edit form (display in currency units) |
| `max_amount_minor` | INTEGER | Edit form |
| `enabled_organisations` | JSONB array | Edit form |
| `enabled_modules` | JSONB array | Edit form |
| `enabled_purpose_types` | JSONB array | Edit form |
| `country_restrictions` | JSONB array | Edit form (deny-list) |
| `callback_url` | VARCHAR | READ ONLY display — must be null (no edit input rendered) |
| `callback_secret_reference` | VARCHAR | Masked display via `SecretReference` VO; "Change reference" → rotate-credential endpoint |
| `capability_flags` | JSONB object | Edit form (checkboxes per capability) |
| `fee_rule_ids` | JSONB array | Display only (fees managed in E21) |
| `daily_limit_minor` | INTEGER | Edit form |
| `is_active` | BOOLEAN | Derived from `account_lifecycle_status` |
| `config_version` | INTEGER | Display; used for optimistic-lock conflict detection |
| `display_label` | VARCHAR | Edit form |
| `display_description` | VARCHAR(255) | Edit form |
| `display_icon_key` | VARCHAR(128) | Edit form (design-system token key, not URL) |
| `display_badge` | VARCHAR(64) | Edit form |
| `display_sort_order` | INTEGER | Edit form |
| `account_lifecycle_status` | VARCHAR(30) | ProviderStatusBadge + lifecycle controls |
| `submitted_by_actor_id` | VARCHAR | Display in approval panel |
| `approved_by_actor_id` | VARCHAR | Display in approval panel |
| `approved_at`, `activated_at`, `suspended_at`, `retired_at` | TIMESTAMPTZ | Version history |
| `rejection_reason` | TEXT | Display in timeline |

### `payment.payment_config_change_drafts` (READ via new list endpoint)

Used by Approval Queue and Version History screens.

| Column | Type | P20 Usage |
|--------|------|-----------|
| `id` | UUID | PK |
| `provider_id` | UUID FK | Parent link |
| `change_type` | VARCHAR | `high_risk_update` / `credential_rotation` / `module_config` (new) |
| `change_payload` | JSONB | Diff source (field → new value); secrets fields shown masked |
| `status` | ENUM | `draft` / `pending_approval` / `approved` / `rejected` / `superseded` |
| `drafted_by_actor_id` | VARCHAR | Display in approval queue |
| `reviewed_by_actor_id` | VARCHAR | Display in approval queue |
| `reviewed_at` | TIMESTAMPTZ | Display |
| `review_notes` | TEXT | Display |
| `account_config_version_at_draft_time` | INTEGER | Conflict detection |
| `created_at` | TIMESTAMPTZ | Timeline ordering |

**Note**: `change_payload` MUST NOT be rendered raw in the UI. The approval queue renders field-level diffs after masking any key that matches `HIGH_RISK_FIELDS = ['merchant_account_reference', 'callback_secret_reference', 'settlement_destination']`.

---

## New Table

### `payment.payment_module_configs`

Stores global module availability and display order configuration. Used by the Module Availability screen.

| Column | Type | Default | Nullable | Description |
|--------|------|---------|----------|-------------|
| `id` | UUID v7 PK | — | NO | |
| `module_key` | VARCHAR(100) | — | NO | e.g. `wallet_topup`, `bill_payment`, `p2p_transfer` |
| `display_name` | VARCHAR(128) | — | NO | Human-readable label for admin UI |
| `is_enabled` | BOOLEAN | TRUE | NO | Global on/off; affects `MODULE_NOT_ENABLED` eligibility stage |
| `display_order` | INTEGER | 0 | NO | Presentation order in mobile option list |
| `description` | TEXT | NULL | YES | Optional admin note |
| `updated_by_actor_id` | VARCHAR(100) | NULL | YES | Last operator to change this |
| `created_at` | TIMESTAMPTZ | now() | NO | |
| `updated_at` | TIMESTAMPTZ | now() | NO | |

**Constraints**:
- `UNIQUE(module_key)` — one config row per module
- Changes to `is_enabled` or `display_order` follow the `PaymentConfigChangeDraft` approval workflow (FR-006/FR-014); the draft is stored with `change_type = 'module_config'` and the live row is only updated after approval

**Indexes**: `pmc_module_key_idx` on `module_key`

**Seeding**: The migration seeds known module keys from `config('payment.known_modules', [])` with `is_enabled = true` and `display_order` matching array index. If the config is empty, no rows are seeded (operator creates them via UI).

---

## New Domain Objects (PHP, no DB table)

### `LifecycleTransitionPayload` value object

```php
final readonly class LifecycleTransitionPayload {
    public function __construct(
        public readonly string  $action,    // 'activate' | 'suspend' | 'restore' | 'retire'
        public readonly string  $reason,    // Required; max 2000 chars
        public readonly string  $actorId,
        public readonly string  $correlationId,
    ) {}
}
```

---

## TypeScript Types (new additions to `lib/api/payment.ts`)

```typescript
export type AccountLifecycleStatus =
  | 'draft'
  | 'pending_approval'
  | 'active'
  | 'suspended'
  | 'retired'
  | 'rejected';

export type LifecycleAction = 'activate' | 'suspend' | 'restore' | 'retire';

export type ConfigDraftStatus =
  | 'draft'
  | 'pending_approval'
  | 'approved'
  | 'rejected'
  | 'superseded';

export interface ConfigDraft {
  id: string;
  provider_id: string;
  change_type: 'high_risk_update' | 'credential_rotation' | 'module_config';
  /** Field-level proposed changes; secret fields are masked */
  change_diff: Record<string, { current: unknown; proposed: unknown }>;
  status: ConfigDraftStatus;
  drafted_by_actor_id: string;
  reviewed_by_actor_id: string | null;
  reviewed_at: string | null;
  review_notes: string | null;
  account_config_version_at_draft_time: number | null;
  created_at: string;
}

export interface ModuleConfig {
  id: string;
  module_key: string;
  display_name: string;
  is_enabled: boolean;
  display_order: number;
  description: string | null;
  updated_by_actor_id: string | null;
}

export interface UpdateAccountPayload {
  supported_currencies?: string[];
  supported_payment_methods?: string[];
  min_amount_minor?: number;
  max_amount_minor?: number;
  daily_limit_minor?: number | null;
  enabled_organisations?: string[];
  enabled_modules?: string[];
  enabled_purpose_types?: string[];
  country_restrictions?: string[];
  capability_flags?: string[];
  display_label?: string;
  display_description?: string | null;
  display_icon_key?: string | null;
  display_badge?: string | null;
  display_sort_order?: number;
  // HIGH RISK — triggers draft if included:
  merchant_account_reference?: string;
  callback_secret_reference?: string;  // stored as key path, never raw secret
}

export interface LifecycleTransitionPayload {
  action: LifecycleAction;
  reason: string;
}

export interface UpdateModuleConfigPayload {
  modules: Array<{
    module_key: string;
    is_enabled?: boolean;
    display_order?: number;
    description?: string | null;
  }>;
}
```

---

## State Machine: Provider Account Lifecycle

```
        [draft]
           │ Submit for approval (POST /config-changes)
           ▼
    [pending_approval]
      │         │
  Approve   Reject
      │         │
      ▼         ▼
  [active]  [rejected] ──→ (re-draft)
      │
  Suspend (POST /lifecycle action=suspend)
      │
      ▼
  [suspended]
      │
  Restore (POST /lifecycle action=restore)
      │
      ▼
  [active]
      │
  Retire (POST /lifecycle action=retire, requires confirmation)
      │
      ▼
  [retired] (terminal)
```

---

## Eligibility Pipeline Reference (from P05 — READ ONLY)

P20 screens surface the eligibility pipeline results via the existing `GET /v1/admin/payment/providers/eligibility-preview` endpoint. No changes to the pipeline itself.

Key eligibility stages relevant to P20 configuration:
- Stage 1: `is_enabled`, `is_suspended`, `is_active` (provider + account flags)
- Stage 2: `enabled_organisations` list
- Stage 3: `enabled_modules` list
- Stage 4: `enabled_purpose_types` list
- Stage 6: `supported_currencies`
- Stage 7: `min_amount_minor` / `max_amount_minor`
