# Channel Adapter Interfaces — E17 Notifications

These PHP interfaces define the contracts that infrastructure channel adapters must implement. They live in `App\Modules\Notification\Domain\Contracts\`.

---

## PushChannelInterface

```php
interface PushChannelInterface
{
    /**
     * Send a push notification to a registered device token.
     *
     * @throws PushChannelException on unrecoverable provider error
     */
    public function send(
        string $deviceTokenValue,
        string $platform, // 'ios' | 'android'
        string $subject,
        string $body,
        array $metadata = []
    ): PushDeliveryResult;
}
```

`PushDeliveryResult`:
- `success: bool`
- `providerRef: ?string` — provider-assigned message ID
- `errorCode: ?string` — provider error code on failure
- `tokenInvalid: bool` — true when provider reports the token is expired/invalid

---

## SmsChannelInterface

```php
interface SmsChannelInterface
{
    /**
     * Send an SMS to a phone number.
     *
     * @throws SmsChannelException on unrecoverable provider error
     */
    public function send(
        string $phoneNumber, // E.164 format
        string $body
    ): SmsDeliveryResult;
}
```

`SmsDeliveryResult`:
- `success: bool`
- `providerRef: ?string`
- `errorCode: ?string`

---

## EmailChannelInterface

```php
interface EmailChannelInterface
{
    /**
     * Send an email notification.
     *
     * @throws EmailChannelException on unrecoverable provider error
     */
    public function send(
        string $emailAddress,
        string $subject,
        string $body
    ): EmailDeliveryResult;
}
```

`EmailDeliveryResult`:
- `success: bool`
- `providerRef: ?string`
- `errorCode: ?string`

---

## DeviceRegistryInterface

```php
interface DeviceRegistryInterface
{
    /**
     * Returns all active push-enabled device tokens for a user.
     *
     * @return DeviceTokenRecord[]
     */
    public function getActiveTokensForUser(string $userId): array;

    /**
     * Marks a device token as invalid (e.g., after push provider rejects it).
     */
    public function invalidateToken(string $deviceTokenId): void;
}
```

`DeviceTokenRecord`:
- `id: string` — token record UUID
- `tokenValue: string` — the raw FCM/APNS token string
- `platform: string` — 'ios' | 'android'

---

## Default (Log) Implementations

Shipped with E17 for local dev and test environments. Bound by default in `NotificationServiceProvider`. Override in production via service provider binding:

| Interface | Log Adapter | Notes |
|-----------|-------------|-------|
| `PushChannelInterface` | `LogPushChannel` | Writes to structured log; always returns success |
| `SmsChannelInterface` | `LogSmsChannel` | Writes to structured log; always returns success |
| `EmailChannelInterface` | `LogEmailChannel` | Writes to structured log; always returns success |

Real adapters are registered in E1000 (Provider-Adapter-Foundation) by overriding the binding in its service provider, without modifying E17 source.
