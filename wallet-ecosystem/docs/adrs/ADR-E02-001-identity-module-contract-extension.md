# ADR-E02-001: Synchronous IdentityModuleContract Extension for Cross-Module Contact Update

**Status**: Accepted  
**Date**: 2026-08-27  
**Epic**: E02 — User & Profile Management

---

## Context

E02's contact-change flows (email/mobile) require that the canonical identifier stored in `identity.users` be updated in sync with `profile.user_profiles`. Both updates must be atomic — if either fails, the other must roll back.

Two options were considered:

1. **Asynchronous event-based update**: E02 publishes an event; E01 subscribes and updates its record.
2. **Synchronous contract call inside a DB transaction**: E02 calls `IdentityModuleContract::updateUserContact()` inside the same `DB::transaction()` that updates `profile.user_profiles`.

## Decision

Option 2 — synchronous `IdentityModuleContract` extension.

Two new methods are added to `IdentityModuleContract`:
- `getUserContactInfo(string $userId): ?UserContactInfo` — read contact data at profile creation
- `updateUserContact(string $userId, ?string $email, ?string $mobile): void` — write contact data synchronously

Both modules share the same PostgreSQL instance. If `updateUserContact()` throws `IdentityContactUpdateException`, the outer `DB::transaction()` in `ContactChangeService` rolls back, restoring `profile.user_profiles` to its pre-update state.

## Rationale

- Atomicity: a single DB transaction spans both tables; no distributed transaction needed
- Simplicity: no new event infrastructure or eventual-consistency complexity
- Module boundary: E02 depends on E01's public contract, not its internals
- Extraction-ready: when services split, the contract becomes a gRPC/HTTP call

## Consequences

- E01 must implement `getUserContactInfo()` and `updateUserContact()` in Phase 34 (T239–T244) before E02 contact-change flows are functional
- Until T239–T244 are complete, calls throw `BadMethodCallException` at runtime
- The `IdentityContactUpdateException` domain exception is owned by E01

---

## Addendum — Plan Deviation: CreateProfileCommand omitted (T255)

**Date**: 2026-09-01

The implementation plan listed `Application/Commands/CreateProfileCommand.php` as a command object to be dispatched by `CreateProfileOnUserRegisteredListener`. This command was not created because:

1. `CreateProfileOnUserRegisteredListener` is the **sole caller** — no HTTP endpoint, CLI command, or test helper invokes profile creation independently.
2. Wrapping inline data in a command DTO would add a layer with zero behaviour, purely ceremonial for this listener.
3. The existing `Application/Commands/` directory already contains all multi-caller commands (UpdatePersonalInfo, UploadPhoto, etc.); profile creation does not share that characteristic.

**Decision**: Profile creation remains inline in the listener. If a second entry point for programmatic profile creation ever emerges (e.g., an admin backfill CLI), a `CreateProfileCommand` should be introduced at that time.
