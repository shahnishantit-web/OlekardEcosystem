# ADR-E02-002: ProfilePhotoStorageContract Abstraction

**Status**: Accepted  
**Date**: 2026-08-27  
**Epic**: E02 — User & Profile Management

---

## Context

Profile photo storage requires a production-grade object store (TBD-E02-001 — provider not yet selected). Hardcoding any specific SDK (S3, GCS, Azure Blob) would block development and make future migration costly.

## Decision

Introduce `ProfilePhotoStorageContract` interface with three operations:
- `store(string $userId, mixed $file): PhotoStorageResult`
- `delete(string $storageKey): void`
- `generateSignedUrl(string $storageKey, int $expiresInSeconds): string`

`LocalDiskProfilePhotoStorage` implements the contract using Laravel's `Storage::disk('local')` and `URL::temporarySignedRoute()` for dev/test environments.

The raw `storageKey` is never exposed in API responses; only the signed URL is returned.

## Rationale

- Defers TBD-E02-001 provider decision without blocking development
- Interface contract ensures interoperability with any future provider
- Signed URL pattern prevents credential exposure in API responses

## Consequences

- Production deployment requires swapping `LocalDiskProfilePhotoStorage` for a real provider (no code change required in domain services)
- `storageKey` must never appear in any API response body (enforced by `ProfileResource`)
