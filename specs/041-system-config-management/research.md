# Research: System Configuration Management (ADM-P1-017)

## Decision 1: Edit form submission strategy — single `submitChangeRequest()` for all edits

**Decision**: Use the existing `ConfigurationApiClient.submitChangeRequest()` for both critical and non-critical key edits. The backend determines whether to apply immediately or create a pending approval based on the key's `requires_approval` flag.

**Rationale**: The existing API client has no `patchKey()` or direct-update method. Adding one would require a new backend endpoint. The `submitChangeRequest()` call returns a `ChangeRequestItem` whose `status` field tells the frontend whether it was applied (`activated`) or queued (`pending_approval`) — sufficient to show the correct success message.

**Alternatives considered**: Adding a `PATCH /v1/admin/configuration/keys/{key}` direct-update endpoint — rejected because E28 is complete and adding a new endpoint is out of scope for this admin UI epic.

---

## Decision 2: Diff preview rendering — side-by-side `<pre>` blocks with `JSON.stringify`

**Decision**: Show the current value and proposed value as side-by-side JSON-formatted `<pre>` blocks before the user confirms submission. Consistent with the existing `configuration-approval-detail.tsx` value diff layout.

**Rationale**: The approval detail page already renders `JSON.stringify(value, null, 2)` in a grid. Using the same pattern keeps the UX consistent. A dedicated diff library (e.g., `react-diff-view`) would add a dependency and is not needed for this use case.

**Alternatives considered**: Inline field-level diff highlighting — rejected as overkill for simple scalar/JSON values.

---

## Decision 3: `requires_approval` flag source — `ConfigurationKeyDetail.requires_approval: boolean`

**Decision**: Add `requires_approval: boolean` to the `ConfigurationKeyDetail` type in `configuration.ts`. The frontend uses this to show the appropriate success message after submission ("Change request submitted for approval" vs "Value updated successfully").

**Rationale**: The backend API already returns this field (E28). It is not needed to gate the API call — `submitChangeRequest()` is always called — but it is needed to give the user accurate feedback.

**Alternatives considered**: Inferring from the resulting `ChangeRequestItem.status` field after submission — viable and simpler, but less informative before the user submits.

---

## Decision 4: Maker/checker guard — compare `user.id` from `useAuth()` with `request.maker_id`

**Decision**: Destructure `user` from `useAuth()` in `configuration-approval-detail.tsx`. Compute `const isOwnChange = user?.id === request?.maker_id`. When `isOwnChange` is true, disable the Approve button and show a "You cannot approve your own change" notice. The Withdraw button remains enabled (a maker may withdraw their own submission).

**Rationale**: `useAuth()` already exposes `user: ActorDTO | null` with `user.id` as the UUID. This is the canonical way to get the logged-in user's identity across the admin app. The backend enforces maker/checker server-side; this is client-side UX enforcement.

**Alternatives considered**: Hiding the Approve button entirely — rejected because "disabled with explanation" is more informative than a missing button.

---

## Decision 5: RBAC gate pattern — access-denied card, same as E40

**Decision**: Use the same access-denied card pattern as `audit-events.tsx` and `compliance-status.tsx` from E40: early return with `<Card><p role="alert">Access denied...</p></Card>` when permission is absent.

**Rationale**: Consistency with all recently implemented pages. RBAC gates are client-side UX; the backend enforces them for every API call.

**Permission mapping**:
- `adminapp.config.read` — view config list, key detail (read-only), version history
- `adminapp.config.write` — edit form visible and enabled on key detail
- `adminapp.config.approve` — approve/reject/withdraw buttons visible on approval detail; approvals list accessible

**Alternatives considered**: Single `adminapp.config.manage` permission — rejected because the spec requires separate read, write, and approve levels to support least-privilege roles.

---

## Decision 6: `has_pending_change` field — add to `ConfigurationKeyItem` type

**Decision**: Add `has_pending_change: boolean` to `ConfigurationKeyItem` in `configuration.ts`. Remove the unsafe cast `(key as ConfigurationKeyItem & { has_pending_change?: boolean })` from `configuration-list.tsx`.

**Rationale**: The backend API already returns this field. The unsafe cast was a TODO left in E33. Properly typing it eliminates a latent TypeScript error.

---

## Decision 7: Test mock strategy — same `STABLE_CLIENT` + `(useAuth as jest.Mock).mockReturnValue` pattern as E40

**Decision**: Use the exact same stable reference pattern established in E40 tests to avoid infinite `useEffect` loops. Module-level `const STABLE_CLIENT = {} as never`. Each test calls `(useAuth as jest.Mock).mockReturnValue({ authenticatedClient: STABLE_CLIENT, user: MOCK_USER, hasPermission: (slug) => mockPerms(slug) })`.

**Rationale**: Proven pattern from E38–E40. Stable `authenticatedClient` reference prevents re-firing of `useEffect([authenticatedClient])` on every render.
