# Domain Events — E05 KYC & Identity Verification

All events extend `BaseIntegrationEvent` and are published to `platform.outbox_events` via `OutboxEventPublisher`. The publisher MUST be called inside an active database transaction along with the state mutation.

## Naming Convention

- **Domain event `eventName()`**: `Kyc.Case.{PastTenseVerb}` (PascalCase — matches existing `Identity.Credential.PinCreated` convention)
- **Audit action**: `kyc.case.{verb}` (lowercase dots — matches existing `identity.device.registered` convention)

These are two separate records: one in `platform.outbox_events` (domain event), one in `platform.audit_events` (audit record).

---

## `Kyc.Case.Approved` (Priority: Critical)

Emitted when a KYC case transitions to `approved`. E06 (Wallet Management) consumes this event.

```
class: KycCaseApprovedEvent extends BaseIntegrationEvent
eventName(): 'Kyc.Case.Approved'
aggregateType(): 'KycCase'
eventVersion(): '1'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "verified_at": "2026-09-17T10:10:00Z",
  "expires_at": "2027-09-17T10:10:00Z",
  "verification_method": "automated | manual",
  "attempt_number": 1
}
```

**When**: Both automated verification pass and manual approval.

---

## `Kyc.Case.Submitted`

Emitted when a KYC case enters `under_review`.

```
eventName(): 'Kyc.Case.Submitted'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "submitted_at": "2026-09-17T10:05:00Z",
  "attempt_number": 1
}
```

---

## `Kyc.Case.Rejected`

Emitted when a Compliance Officer rejects a case.

```
eventName(): 'Kyc.Case.Rejected'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "reviewer_id": "01966bbb-...",
  "attempt_number": 1
}
```

**Note**: `rejection_reason` is NOT included in the event payload — it is stored in `kyc.kyc_cases` and returned via the status API.

---

## `Kyc.Case.NeedsInfo`

Emitted when a Compliance Officer requests additional information.

```
eventName(): 'Kyc.Case.NeedsInfo'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "reviewer_id": "01966bbb-...",
  "attempt_number": 1
}
```

---

## `Kyc.Case.Resubmitted`

Emitted when a customer resubmits a rejected/needs-info case.

```
eventName(): 'Kyc.Case.Resubmitted'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "attempt_number": 2
}
```

---

## `Kyc.Case.Expired` (Priority: Critical)

Emitted when the expiry detection job transitions a case to `expired`. E06 consumes this to suspend wallet access.

```
eventName(): 'Kyc.Case.Expired'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "expired_at": "2027-09-17T02:00:00Z",
  "was_expires_at": "2027-09-17T10:10:00Z"
}
```

---

## `Kyc.Case.ReverificationTriggered` (Priority: Critical)

Emitted when an Admin triggers reverification of an approved/expired case. E06 consumes this to suspend wallet access.

```
eventName(): 'Kyc.Case.ReverificationTriggered'
aggregateType(): 'KycCase'
```

**Payload**:
```json
{
  "case_id": "01966b4c-...",
  "user_id": "01966aaa-...",
  "triggered_by": "01966ccc-...",
  "attempt_number": 3
}
```

---

## Audit Event Actions

Written to `platform.audit_events`. All actions use the `kyc.case.{verb}` lower-kebab-case convention.

| Action | When |
|--------|------|
| `kyc.case.initiated` | Case started |
| `kyc.case.personal-information-captured` | Personal info saved |
| `kyc.case.document-uploaded` | Document stored and validated |
| `kyc.case.submitted` | Case submitted for review |
| `kyc.case.approved` | Case approved |
| `kyc.case.rejected` | Case rejected |
| `kyc.case.needs-info` | Additional info requested |
| `kyc.case.resubmitted` | Customer resubmitted |
| `kyc.case.expired` | Expiry detected |
| `kyc.case.reverification-triggered` | Reverification triggered |
| `kyc.duplicate-identity.detected` | Duplicate national ID hash found |
