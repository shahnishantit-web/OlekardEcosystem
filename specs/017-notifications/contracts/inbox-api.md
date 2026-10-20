# API Contract — In-App Notification Inbox

**Base path**: `/v1/notifications/inbox`
**Auth**: `auth:sanctum`
**Permission**: `notifications.inbox.read` (own inbox only)

---

## GET /v1/notifications/inbox

Returns the authenticated user's in-app notification history, paginated and ordered by newest first.

### Query Parameters

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `page` | integer | 1 | Page number |
| `per_page` | integer | 20 | Max 50 |
| `unread_only` | boolean | false | Filter to unread notifications only |
| `category` | string | (all) | Filter by `financial`, `security`, `operational`, `marketing` |

### Response 200

```json
{
  "data": [
    {
      "id": "01932b8f-7c3e-7c3e-8000-000000000011",
      "notification_type": "payment.qr.received",
      "category": "financial",
      "subject": "Payment received: 250.00 BWP",
      "body": "A payment of 250.00 BWP has been received from customer ref CUS-001. Reference: PAY-0123.",
      "read": false,
      "created_at": "2026-10-01T14:22:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 47,
    "last_page": 3
  }
}
```

---

## GET /v1/notifications/inbox/unread-count

Returns the count of unread in-app notifications for the authenticated user.

### Response 200

```json
{
  "data": {
    "unread_count": 3
  }
}
```

---

## GET /v1/notifications/inbox/{id}

Returns a single in-app notification by ID. Only accessible if the notification's `recipient_id` matches the authenticated user.

### Response 200

```json
{
  "data": {
    "id": "01932b8f-7c3e-7c3e-8000-000000000011",
    "notification_type": "payment.qr.received",
    "category": "financial",
    "mandatory": true,
    "subject": "Payment received: 250.00 BWP",
    "body": "A payment of 250.00 BWP has been received from customer ref CUS-001. Reference: PAY-0123.",
    "read": false,
    "created_at": "2026-10-01T14:22:00Z"
  }
}
```

### Errors

| Code | Status | Condition |
|------|--------|-----------|
| `NOT_FOUND` | 404 | ID does not exist or belongs to another user |

---

## PATCH /v1/notifications/inbox/read

Marks one or more in-app notifications as read. Accepts either an array of IDs or a flag to mark all as read. Notifications not belonging to the authenticated user are silently ignored (not an error).

### Request Body — Mark specific IDs

```json
{
  "ids": [
    "01932b8f-7c3e-7c3e-8000-000000000011",
    "01932b8f-7c3e-7c3e-8000-000000000012"
  ]
}
```

### Request Body — Mark all as read

```json
{
  "mark_all": true
}
```

**Validation rules**:
- Provide either `ids` (non-empty array of UUIDs) or `mark_all: true` — not both
- `ids` maximum 100 items per request

### Response 200

```json
{
  "data": {
    "marked_count": 2
  }
}
```

### Errors

| Code | Status | Condition |
|------|--------|-----------|
| `VALIDATION_ERROR` | 422 | Both `ids` and `mark_all` provided, or `ids` is empty |
| `UNAUTHENTICATED` | 401 | No valid session token |

---

## Response Envelope

All responses follow the platform standard envelope. Errors use the same structure as `preferences-api.md`.
