# Tasks: E17 â€” Notifications

**Input**: Design documents from `specs/017-notifications/`

**Prerequisites**: spec.md âœ“ plan.md âœ“ research.md âœ“ data-model.md âœ“ contracts/ âœ“ quickstart.md âœ“

**Organization**: Tasks grouped by user story. US0 (P0) â†’ US3 (P0) â†’ US1 (P1) â†’ US2 (P1) in dependency order.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to
- Paths are relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Module skeleton and service provider wiring.

- [X] T001 Create `App\Modules\Notification` directory tree matching plan.md module tree under `app/Modules/Notification/` (Application/Jobs, Application/Listeners, Application/Services, Domain/Contracts, Domain/DTO, Domain/Enums, Domain/Exceptions, Infrastructure/Adapters, Infrastructure/Channels, Infrastructure/Http/Controllers, Infrastructure/Http/Requests, Infrastructure/Http/Resources, Infrastructure/Migrations, Infrastructure/Models, Providers)
- [X] T002 Create `NotificationServiceProvider` skeleton in `app/Modules/Notification/Providers/NotificationServiceProvider.php` â€” extends `ServiceProvider`; `register()` binds interfaces to defaults; `boot()` registers event listeners and Horizon queue config; register provider in `config/app.php` or `bootstrap/providers.php`
- [X] T003 [P] Create `NotificationPermissions` constants class in `app/Modules/Notification/Domain/NotificationPermissions.php` â€” constants `PREFERENCES_MANAGE = 'notifications.preferences.manage'`, `INBOX_READ = 'notifications.inbox.read'`, `ADMIN_SEND = 'notifications.admin.send'`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: All database tables, enums, models, contracts, DTOs, exceptions, log adapters, and base services required before any user story can be implemented.

**âš ï¸ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Create migration `2026_10_01_017000_create_notification_schema.php` in `database/migrations/` â€” `CREATE SCHEMA IF NOT EXISTS notification`
- [X] T005 [P] Create migration `2026_10_01_017100_create_notifications_table.php` in `database/migrations/` â€” table `notification.notifications` with columns: `id` (UUID v7 PK), `source_event_id` (UUID NOT NULL), `source_event_type` (varchar 100), `notification_type` (varchar 100), `category` (enum: financial/security/operational/marketing), `mandatory` (boolean default false), `recipient_id` (UUID), `subject` (text), `body` (text), `status` (enum: queued/dispatching/partially_delivered/delivered/failed), `read_at` (timestamp nullable), `correlation_id` (UUID), `created_at`, `updated_at`; UNIQUE `(source_event_id, notification_type)`; INDEX `(recipient_id, created_at DESC)`; INDEX `(recipient_id, read_at)`
- [X] T006 [P] Create migration `2026_10_01_017200_create_notification_deliveries_table.php` in `database/migrations/` â€” table `notification.notification_deliveries` with columns: `id` (UUID v7 PK), `notification_id` (UUID FK NOT NULL), `channel` (enum: push/sms/email/in_app), `status` (enum: queued/sent/delivered/failed/permanently_failed), `attempt_count` (smallint default 0), `last_attempt_at` (timestamp nullable), `next_retry_at` (timestamp nullable), `provider_ref` (varchar 255 nullable), `error_code` (varchar 50 nullable), `error_message` (varchar 500 nullable), `created_at`, `updated_at`; UNIQUE `(notification_id, channel)`; INDEX `(status, next_retry_at)`
- [X] T007 [P] Create migration `2026_10_01_017300_create_notification_templates_table.php` in `database/migrations/` â€” table `notification.notification_templates` with columns: `id` (UUID v7 PK), `key` (varchar 100 UNIQUE), `category` (enum), `subject_template` (text), `body_template` (text), `supported_channels` (JSON), `variables` (JSON), `active` (boolean default true), `created_at`, `updated_at`
- [X] T008 [P] Create migration `2026_10_01_017400_create_notification_preferences_table.php` in `database/migrations/` â€” table `notification.notification_preferences` with columns: `id` (UUID v7 PK), `user_id` (UUID UNIQUE), `push_enabled_categories` (JSON default `["financial","security","operational"]`), `sms_enabled_categories` (JSON default `["financial","security"]`), `email_enabled_categories` (JSON default `["security"]`), `marketing_opted_in` (boolean default false), `created_at`, `updated_at`
- [X] T009 Create migration `2026_10_01_017500_seed_notification_templates.php` in `database/migrations/` â€” seed 11 templates: `payment.qr.received`, `payment.qr.sent`, `payment.funding.received`, `payment.transfer.received`, `payment.transfer.sent`, `payment.withdrawal.processed`, `payment.commission.credited`, `payment.bill.paid`, `security.new_device`, `security.password_changed`, `security.suspicious_activity`; each with subject_template, body_template using `{{variable}}` placeholders, supported_channels JSON array, variables JSON object, active=true (see data-model.md for full variable lists); **also** seed notification permissions in the same migration: call the platform permission-seeding helper to idempotently register `['notifications.preferences.manage', 'notifications.inbox.read', 'notifications.admin.send']`; **before coding**: verify the exact class path and method name used in E04 (likely `\App\Modules\Auth\Application\Services\PermissionSeeder::seedIfMissing()` or an equivalent `DB::table('permissions')->insertOrIgnore(...)` pattern) â€” grep E04 migrations for the pattern used there and replicate it exactly
- [X] T010 [P] Create `NotificationChannel` enum in `app/Modules/Notification/Domain/Enums/NotificationChannel.php` â€” cases: Push, Sms, Email, InApp; values: 'push', 'sms', 'email', 'in_app'
- [X] T011 [P] Create `NotificationCategory` enum in `app/Modules/Notification/Domain/Enums/NotificationCategory.php` â€” cases: Financial, Security, Operational, Marketing; values: 'financial', 'security', 'operational', 'marketing'; add `isMandatory(): bool` returning true for Financial and Security
- [X] T012 [P] Create `NotificationStatus` enum in `app/Modules/Notification/Domain/Enums/NotificationStatus.php` â€” cases: Queued, Dispatching, PartiallyDelivered, Delivered, Failed
- [X] T013 [P] Create `DeliveryStatus` enum in `app/Modules/Notification/Domain/Enums/DeliveryStatus.php` â€” cases: Queued, Sent, Delivered, Failed, PermanentlyFailed
- [X] T014 [P] Create `NotificationModel` in `app/Modules/Notification/Infrastructure/Models/NotificationModel.php` â€” `$table = 'notification.notifications'`; UUID v7 primary key; `$incrementing = false`; `$keyType = 'string'`; casts for NotificationCategory, NotificationStatus enums and Carbon timestamps; `deliveries()` hasMany `NotificationDeliveryModel`; define `$fillable`
- [X] T015 [P] Create `NotificationDeliveryModel` in `app/Modules/Notification/Infrastructure/Models/NotificationDeliveryModel.php` â€” `$table = 'notification.notification_deliveries'`; UUID v7 PK; casts for NotificationChannel, DeliveryStatus enums; `notification()` belongsTo `NotificationModel`
- [X] T016 [P] Create `NotificationTemplateModel` in `app/Modules/Notification/Infrastructure/Models/NotificationTemplateModel.php` â€” `$table = 'notification.notification_templates'`; UUID v7 PK; casts for NotificationCategory enum; JSON casts for `supported_channels` and `variables`
- [X] T017 [P] Create `NotificationPreferenceModel` in `app/Modules/Notification/Infrastructure/Models/NotificationPreferenceModel.php` â€” `$table = 'notification.notification_preferences'`; UUID v7 PK; JSON casts for `push_enabled_categories`, `sms_enabled_categories`, `email_enabled_categories`; boolean cast for `marketing_opted_in`
- [X] T018 [P] Create domain channel contracts in `app/Modules/Notification/Domain/Contracts/`: `PushChannelInterface.php` (send: deviceTokenValue, platform, subject, body, metadata â†’ PushDeliveryResult), `SmsChannelInterface.php` (send: phoneNumber, body â†’ SmsDeliveryResult), `EmailChannelInterface.php` (send: emailAddress, subject, body â†’ EmailDeliveryResult), `DeviceRegistryInterface.php` (getActiveTokensForUser(userId) â†’ DeviceTokenRecord[]; invalidateToken(tokenId): void) â€” per contracts/channel-interfaces.md exact signatures; **also** create `UserContactServiceInterface.php` with three methods: `userExists(string $userId): bool`, `getPhoneForUser(string $userId): ?string` (returns E.164 phone or null if not set), `getEmailForUser(string $userId): ?string` (returns email address or null if not set)
- [X] T019 [P] Create DTOs in `app/Modules/Notification/Domain/DTO/`: `CreateNotificationCommand.php` (readonly: sourceEventId, sourceEventType, notificationType, category: NotificationCategory, mandatory: bool, recipientId, variables: array, correlationId), `NotificationContent.php` (renderedSubject, renderedBody, supportedChannels: array), `PushDeliveryResult.php` (success: bool, providerRef: ?string, errorCode: ?string, tokenInvalid: bool), `SmsDeliveryResult.php` (success, providerRef?, errorCode?), `EmailDeliveryResult.php` (success, providerRef?, errorCode?), `DeviceTokenRecord.php` (id: string, tokenValue: string, platform: string)
- [X] T020 [P] Create domain exceptions in `app/Modules/Notification/Domain/Exceptions/`: `DuplicateNotificationException.php`, `TemplateNotFoundException.php`, `MandatoryNotificationCannotBeDisabledException.php`, `RecipientNotFoundException.php`
- [X] T021 [P] Create log channel adapters in `app/Modules/Notification/Infrastructure/Channels/`: `LogPushChannel.php` (implements PushChannelInterface; writes `Log::info('notification.push.log', [...])` with channel, recipient, subject, body; returns `PushDeliveryResult(success:true, tokenInvalid:false)`); `LogSmsChannel.php` (implements SmsChannelInterface; logs + returns success); `LogEmailChannel.php` (implements EmailChannelInterface; logs + returns success)
- [X] T022 Create E03 adapters in `app/Modules/Notification/Infrastructure/Adapters/`: (a) `E03DeviceRegistryAdapter.php` â€” implements `DeviceRegistryInterface`; constructor receives E03's `DeviceManagementService`; `getActiveTokensForUser(string $userId): array` delegates to `DeviceManagementService::getActiveTokensForUser($userId)` and maps to `DeviceTokenRecord[]`; `invalidateToken(string $tokenId): void` delegates to `DeviceManagementService::invalidateToken($tokenId)`; (b) `E03UserContactAdapter.php` â€” implements `UserContactServiceInterface`; constructor receives E03's `UserManagementService`; `userExists(string $userId): bool` delegates to `UserManagementService::findById($userId) !== null`; `getPhoneForUser(string $userId): ?string` returns the verified phone number from the user profile or null if none registered; `getEmailForUser(string $userId): ?string` returns the verified email address from the user profile or null if none registered
- [X] T023 Implement `NotificationTemplateService` in `app/Modules/Notification/Application/Services/NotificationTemplateService.php` â€” `render(string $key, array $variables): NotificationContent`: (1) query `NotificationTemplateModel::where('key', $key)->where('active', true)->firstOrFail()` â€” throw `TemplateNotFoundException` if not found; (2) render subject and body with `str_replace` over `{{variable_name}}` placeholders; (3) for each unresolved placeholder: substitute `[Unknown]`, emit `Log::warning('notification.template.missing_variable', ['key' => $key, 'variable' => $name])`; (4) return `NotificationContent(subject, body, $template->supported_channels)`
- [X] T024 Create `NotificationPreferenceService` skeleton in `app/Modules/Notification/Application/Services/NotificationPreferenceService.php` â€” constructor receives `NotificationPreferenceModel`, `AuditServiceInterface`, `DeviceRegistryInterface`, `UserContactServiceInterface` (all four required; the latter two are needed by T043's mandatory-channel detection logic); implement TWO methods in this task: (1) `getOrCreateForUser(string $userId): NotificationPreferenceModel` â€” `NotificationPreferenceModel::firstOrCreate(['user_id' => $userId], ['push_enabled_categories' => ['financial','security','operational'], 'sms_enabled_categories' => ['financial','security'], 'email_enabled_categories' => ['security'], 'marketing_opted_in' => false])` â€” this must exist in Foundation because T043 (US3) calls it internally; (2) stub `getEnabledChannels(string $userId, NotificationCategory $category, bool $mandatory, ?string $notificationId = null): array` returning `[NotificationChannel::Push, NotificationChannel::Sms, NotificationChannel::Email, NotificationChannel::InApp]` â€” full channel set stub with correct 4-parameter signature from day one; full preference filtering implemented in T043 (US3); `updateForUser()` added in T061 (US2)
- [X] T025 Complete `NotificationServiceProvider` bindings in `app/Modules/Notification/Providers/NotificationServiceProvider.php` â€” `register()`: bind `PushChannelInterface` â†’ `LogPushChannel`, `SmsChannelInterface` â†’ `LogSmsChannel`, `EmailChannelInterface` â†’ `LogEmailChannel`, `DeviceRegistryInterface` â†’ `E03DeviceRegistryAdapter`, `UserContactServiceInterface` â†’ `E03UserContactAdapter`; `boot()`: add Horizon `queues` config for `notifications-critical` (balance: simple, minProcesses: 1, maxProcesses: 5) and `notifications-bulk` (balance: auto, minProcesses: 1, maxProcesses: 2); **do NOT seed permissions here** â€” permissions are seeded in migration T009 to ensure they exist before the application boots

**Checkpoint**: Foundation ready â€” all tables, models, enums, contracts, adapters, and base services in place. User story implementation can begin.

---

## Phase 3: User Story 0 â€” Transactional Notification Delivery (Priority: P0) ðŸŽ¯ MVP

**Goal**: When a financial or security event is published, the affected party receives a notification on their enabled channels within 5 seconds. The delivery pipeline handles push, SMS, email, and in-app channels in parallel. Idempotency prevents duplicate notifications on event replay.

**Independent Test**: Publish `QrPaymentCompletedEvent` â†’ run queued jobs â†’ assert 2 `Notification` rows + `in_app` `NotificationDelivery` rows with status `delivered`. Publish same event twice â†’ assert no duplicate rows. (quickstart SC-1, SC-2, SC-3)

- [X] T026 [US0] Implement `NotificationService::createFromEvent()` in `app/Modules/Notification/Application/Services/NotificationService.php` â€” constructor receives `NotificationTemplateService`, `NotificationPreferenceService`, `UserContactServiceInterface`, `AuditServiceInterface`; `createFromEvent(CreateNotificationCommand $cmd): NotificationModel`: (1) idempotency: `NotificationModel::where(['source_event_id' => $cmd->sourceEventId, 'notification_type' => $cmd->notificationType])->first()` â€” if found, return existing model without re-dispatching; (2) validate recipient: call `UserContactServiceInterface::userExists($cmd->recipientId)` â€” if false, emit `Log::warning('notification.recipient_not_found', ['recipient_id' => $cmd->recipientId, 'notification_type' => $cmd->notificationType, 'correlation_id' => $cmd->correlationId])` and throw `RecipientNotFoundException`; (3) render template via `NotificationTemplateService::render($cmd->notificationType, $cmd->variables)`; (4) persist `NotificationModel` with status=`queued`, mandatory=$cmd->mandatory, category=$cmd->category; (5) determine queue: `$cmd->mandatory || $cmd->category->isMandatory()` â†’ `notifications-critical`, else `notifications-bulk`; (6) `DispatchNotificationJob::dispatch($notification->id)->onQueue($queue)`; (7) `AuditServiceInterface::record(action: 'notification.created', subjectId: $notification->id, actorId: null, correlationId: $cmd->correlationId, ...)`; (8) return `NotificationModel`
- [X] T027 [US0] Implement `DispatchNotificationJob` in `app/Modules/Notification/Application/Jobs/DispatchNotificationJob.php` â€” implements `ShouldQueue`; constructor receives `string $notificationId`; `handle(NotificationDeliveryService $svc)`: loads `NotificationModel::findOrFail($notificationId)`, calls `$svc->dispatchToChannels($notification)`; catches and logs any exception at ERROR level with structured context including notification_id and correlation_id
- [X] T028 [US0] Implement `NotificationDeliveryService` in `app/Modules/Notification/Application/Services/NotificationDeliveryService.php` â€” constructor receives `NotificationPreferenceService`, `DeviceRegistryInterface`; `dispatchToChannels(NotificationModel $notification): void`: (1) set notification status â†’ `dispatching`; (2) call `NotificationPreferenceService::getEnabledChannels($notification->recipient_id, $notification->category, $notification->mandatory, $notification->id)` â€” pass `$notification->id` as the 4th argument so the mandatory-override audit can record the notification subject ID; (3) for push: only if `DeviceRegistryInterface::getActiveTokensForUser($notification->recipient_id)` returns â‰¥1 token AND push is in enabled channels; (4) for each enabled channel, `NotificationDeliveryModel::create(['notification_id' => ..., 'channel' => ..., 'status' => 'queued'])` then dispatch matching job; (5) if channel list is empty for a non-mandatory marketing notification: `Log::info('notification.marketing_discarded', ['recipient_id' => ..., 'notification_type' => ..., 'correlation_id' => ...])`
- [X] T029 [P] [US0] Implement `DeliverInAppNotificationJob` in `app/Modules/Notification/Application/Jobs/DeliverInAppNotificationJob.php` â€” implements `ShouldQueue`; `handle()`: loads `NotificationDeliveryModel` by id; sets `status = delivered`, `last_attempt_at = now()`; saves; updates parent `NotificationModel` status (check if all deliveries done â†’ delivered; else â†’ dispatching); in-app delivery always succeeds â€” no retry, no external call
- [X] T030 [P] [US0] Implement `DeliverPushNotificationJob` (base, retry added in US1 T048) in `app/Modules/Notification/Application/Jobs/DeliverPushNotificationJob.php` â€” implements `ShouldQueue`; `handle(PushChannelInterface $push, DeviceRegistryInterface $registry)`: loads `NotificationDeliveryModel` + parent `NotificationModel`; increments `attempt_count`, sets `last_attempt_at = now()`; gets tokens via `$registry->getActiveTokensForUser($notification->recipient_id)`; calls `$push->send(token->tokenValue, token->platform, subject, body)` for each token; on success: status â†’ `sent`, `provider_ref = result->providerRef`; on `result->tokenInvalid = true`: `$registry->invalidateToken($token->id)`, status â†’ `failed`, do NOT throw (token is gone, no retry); on exception: status â†’ `failed`, throw to trigger Horizon retry (retry counts added in T048)
- [X] T031 [P] [US0] Implement `DeliverSmsNotificationJob` (base) in `app/Modules/Notification/Application/Jobs/DeliverSmsNotificationJob.php` â€” implements `ShouldQueue`; `handle(SmsChannelInterface $sms, UserContactServiceInterface $contacts)`: loads delivery + notification; increments attempt_count; retrieves phone via `$contacts->getPhoneForUser($notification->recipient_id)`; if null: set delivery status â†’ `failed`, `error_code = 'NO_PHONE_ON_RECORD'`, save â€” do NOT throw (no phone will ever appear without a separate user action, retrying is pointless), emit `Log::warning('notification.sms.no_phone', ['recipient_id' => ..., 'notification_id' => ...])`; else: call `$sms->send($phone, $notification->body)`; on success: status â†’ `sent`; on provider exception: status â†’ `failed`, throw (retry added in T049)
- [X] T032 [P] [US0] Implement `DeliverEmailNotificationJob` (base) in `app/Modules/Notification/Application/Jobs/DeliverEmailNotificationJob.php` â€” same pattern; `handle(EmailChannelInterface $email, UserContactServiceInterface $contacts)`: retrieves email via `$contacts->getEmailForUser($notification->recipient_id)`; if null: set delivery status â†’ `failed`, `error_code = 'NO_EMAIL_ON_RECORD'`, save â€” do NOT throw (same rationale as SMS: no retry benefit), emit `Log::warning('notification.email.no_email', ['recipient_id' => ..., 'notification_id' => ...])`; else: calls `$email->send($emailAddress, $notification->subject, $notification->body)`; on provider exception: throw (retry added in T050)
- [X] T033 [US0] Implement `QrPaymentCompletedListener` in `app/Modules/Notification/Application/Listeners/QrPaymentCompletedListener.php` â€” implements `ShouldQueue`; `public string $queue = 'notifications-critical'`; `handle(QrPaymentCompletedEvent $event)`: call `NotificationService::createFromEvent()` TWICE â€” (1) merchant: `notificationType='payment.qr.received'`, `recipientId=$event->merchantId`, `mandatory=true`, `variables=['amount' => formatAmount($event->amount), 'currency' => $event->currency, 'customer_ref' => substr($event->customerId, 0, 8), 'payment_ref' => $event->paymentReference]`; (2) customer: `notificationType='payment.qr.sent'`, `recipientId=$event->customerId`, `mandatory=true`, `variables=['amount' => formatAmount($event->amount), 'currency' => $event->currency, 'merchant_name' => $event->merchantName ?? substr($event->merchantId, 0, 8), 'payment_ref' => $event->paymentReference]`; **note**: verify E16's `QrPaymentCompletedEvent` carries a `merchantName` string field â€” use it if present; if the field does not exist, use `substr($event->merchantId, 0, 8)` as a readable fallback and raise a request to add `merchantName` to the E16 event contract; both commands carry `sourceEventId=$event->paymentReference` (the stable QR transaction UUID always present on this event â€” **never fall back to `Str::uuid()`** as a new UUID generated on each retry breaks the UNIQUE idempotency constraint) and `sourceEventType=QrPaymentCompletedEvent::class`
- [X] T034 [P] [US0] Implement `FundingCompletedListener` in `app/Modules/Notification/Application/Listeners/FundingCompletedListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(FundingCompletedEvent $event)`: one notification â€” `notificationType='payment.funding.received'`, `recipientId=$event->walletOwnerId`, `mandatory=true`, `sourceEventId=$event->transactionId`, variables: amount, currency, source, reference
- [X] T035 [P] [US0] Implement `TransferCompletedListener` in `app/Modules/Notification/Application/Listeners/TransferCompletedListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(TransferCompletedEvent $event)`: two notifications â€” sender `payment.transfer.sent` + recipient `payment.transfer.received`; both mandatory=true, both use `sourceEventId=$event->transferId` (the composite UNIQUE key `(transferId, notificationType)` distinguishes the two rows)
- [X] T036 [P] [US0] Implement `WithdrawalProcessedListener` in `app/Modules/Notification/Application/Listeners/WithdrawalProcessedListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(WithdrawalProcessedEvent $event)`: one notification â€” `payment.withdrawal.processed`, mandatory=true, `sourceEventId=$event->withdrawalId`
- [X] T037 [P] [US0] Implement `CommissionCreditedListener` in `app/Modules/Notification/Application/Listeners/CommissionCreditedListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(CommissionCreditedEvent $event)`: one notification â€” `payment.commission.credited`, mandatory=true, `sourceEventId=$event->commissionId`
- [X] T038 [P] [US0] Implement `BillPaymentCompletedListener` in `app/Modules/Notification/Application/Listeners/BillPaymentCompletedListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(BillPaymentCompletedEvent $event)`: one notification â€” `payment.bill.paid`, mandatory=true, `sourceEventId=$event->billPaymentId`
- [X] T039 [US0] Implement `SecurityAlertListener` in `app/Modules/Notification/Application/Listeners/SecurityAlertListener.php` â€” implements `ShouldQueue`; queue: `notifications-critical`; `handle(SecurityAlertEvent $event)`: map `$event->type` to template key (`new_device` â†’ `security.new_device`, `password_changed` â†’ `security.password_changed`, `suspicious_activity` â†’ `security.suspicious_activity`); mandatory=true, category=Security, `sourceEventId=$event->alertId`; **note**: verify E03's `SecurityAlertEvent` carries an `alertId` field before coding â€” if absent, use `$event->id` or the equivalent stable identifier on that event; dispatch via `NotificationService::createFromEvent()`
- [X] T040 [US0] Register all event listeners in `NotificationServiceProvider::boot()` via `Event::listen()`: `QrPaymentCompletedEvent::class â†’ QrPaymentCompletedListener::class` plus analogous entries for T034â€“T039 (FundingCompleted, TransferCompleted, WithdrawalProcessed, CommissionCredited, BillPaymentCompleted, SecurityAlert) in `app/Modules/Notification/Providers/NotificationServiceProvider.php`
- [X] T041 [US0] Unit test: `NotificationService` idempotency â€” create in-memory `NotificationModel` stub with existing `(source_event_id, notification_type)`; call `createFromEvent()` twice with same command; assert `DispatchNotificationJob` dispatched exactly once; assert `AuditServiceInterface::record` called once; assert returned model on second call is the existing one in `tests/Unit/Notification/Application/NotificationServiceTest.php`
- [X] T042 [US0] Integration test: `QrPaymentCompletedEvent` â†’ full delivery â€” dispatch event, run queued jobs via `Queue::fake()` or `$this->artisan('queue:work --once')`; assert 2 rows in `notification.notifications`; assert `notification_deliveries` has `channel=in_app, status=delivered` for each recipient; publish same event again; assert row count unchanged in `tests/Integration/Notification/QrPaymentNotificationTest.php`

**Checkpoint**: US0 complete â€” financial events produce in-app notifications; idempotency enforced; 7 event listeners registered and wired.

---

## Phase 4: User Story 3 â€” Mandatory Financial Notifications (Priority: P0)

**Goal**: Mandatory notifications (financial, security) bypass user preference opt-outs and are dispatched to all physically-available channels. Marketing notifications default to opted-out and are silently discarded unless user has opted in. Compliance audit record written when mandatory override is applied.

**Independent Test**: Create preference with all non-financial channels disabled. Dispatch a financial notification. Assert `in_app` delivery row exists. Assert audit log contains `notification.mandatory_override_applied`. Dispatch a marketing notification to opted-out user. Assert no `notifications` row created. (quickstart SC-8, SC-9)

- [X] T043 [US3] Replace stub `NotificationPreferenceService::getEnabledChannels()` with full implementation in `app/Modules/Notification/Application/Services/NotificationPreferenceService.php` â€” **full signature**: `getEnabledChannels(string $userId, NotificationCategory $category, bool $mandatory, ?string $notificationId = null): array`; constructor injections `DeviceRegistryInterface` and `UserContactServiceInterface` are already wired from T024; implementation: (1) if `$mandatory === true` OR `$category->isMandatory()`: determine physically available channels â€” InApp always included; Push if `$this->deviceRegistry->getActiveTokensForUser($userId)` returns â‰¥1 token; Sms if `$this->contactService->getPhoneForUser($userId) !== null`; Email if `$this->contactService->getEmailForUser($userId) !== null`; load stored preference via `getOrCreateForUser($userId)` â€” if stored preference would have excluded any of the above channels, emit `$this->auditService->record(action: 'notification.mandatory_override_applied', subjectId: $notificationId, actorId: null, meta: ['category' => $category->value, 'userId' => $userId])` (`$notificationId` is nullable; pass null when not yet persisted, e.g., in unit tests); return all available channels regardless of stored preference; (2) if `$category === NotificationCategory::Marketing`: load preference via `getOrCreateForUser($userId)`; if `$preference->marketing_opted_in === false`, return `[]`; (3) else: load preference; return channels whose JSON array includes `$category->value`
- [X] T044 [US3] Verify `marketing_discarded` log in `NotificationDeliveryService::dispatchToChannels()` in `app/Modules/Notification/Application/Services/NotificationDeliveryService.php` â€” T028 step (5) already specifies this log; confirm the implemented T028 contains `Log::info('notification.marketing_discarded', ['recipient_id' => ..., 'notification_type' => ..., 'correlation_id' => ...])` at the point where `getEnabledChannels()` returns `[]` for a non-mandatory notification; add it only if T028 implementation omitted it â€” do not create a second log statement
- [X] T045 [US3] Unit test: mandatory override â€” mock `NotificationPreferenceModel` returning SMS disabled for financial; mock `DeviceRegistryInterface` returning 1 token; mock `UserContactServiceInterface::getPhoneForUser()` returning a phone; mock `UserContactServiceInterface::getEmailForUser()` returning an email; call `getEnabledChannels(userId, Financial, true, 'test-notification-uuid')`; assert all 4 channels (Push, Sms, Email, InApp) returned; assert `AuditServiceInterface::record` called with action `notification.mandatory_override_applied` and `subjectId = 'test-notification-uuid'` in `tests/Unit/Notification/Application/NotificationPreferenceServiceTest.php`
- [X] T046 [US3] Unit test: marketing opt-out â€” mock preference with `marketing_opted_in=false`; call `getEnabledChannels(userId, Marketing, false)`; assert empty array returned; mock delivery service; assert no `NotificationDeliveryModel` created and no job dispatched in `tests/Unit/Notification/Application/NotificationDeliveryServiceTest.php`
- [X] T047 [US3] Concurrency test: two simultaneous `createFromEvent()` calls with identical `(source_event_id, notification_type)` â€” use `DB::transaction()` with overlapping test; assert exactly 1 `Notification` row (UNIQUE constraint absorbs duplicate); no `DuplicateKeyException` surfaces to caller in `tests/Unit/Notification/Application/NotificationServiceConcurrencyTest.php`

**Checkpoint**: US3 complete â€” mandatory override active; marketing gated; compliance audit records written.

---

## Phase 5: User Story 1 â€” Delivery Status Tracking & Retry (Priority: P1)

**Goal**: All failed channel deliveries are automatically retried with exponential backoff. After max retries, delivery is marked `permanently_failed` and an audit event is emitted. In-app notification inbox is accessible via REST API with read/unread state and accurate unread count.

**Independent Test**: Bind `PushChannelInterface` to `FailOnceLogPushChannel` test double. Dispatch push notification. Assert delivery `attempt_count=2`, `status=delivered` after retry. Assert `GET /v1/notifications/inbox` returns the notification. Assert `PATCH /v1/notifications/inbox/read` decrements unread count. (quickstart SC-6, SC-10)

- [X] T048 [US1] Add retry logic to `DeliverPushNotificationJob` in `app/Modules/Notification/Application/Jobs/DeliverPushNotificationJob.php` â€” add `public int $tries` reading `config('notifications.retry.max_attempts', 5)`; add `public function backoff(): array` returning `[30, 60, 120, 240, 480]` with values from `config('notifications.retry.initial_delay_seconds', 30)` as base (doubling each step); add `public function failed(\Throwable $e): void`: load delivery, set status â†’ `permanently_failed`, save; `AuditServiceInterface::record(action: 'notification.permanently_failed', subjectId: $deliveryId, meta: ['channel' => 'push', 'error' => $e->getMessage()])`; `Log::error('notification.push.permanently_failed', ['delivery_id' => ..., 'notification_id' => ..., 'error' => $e->getMessage()])` for ops alert; update parent `NotificationModel` status
- [X] T049 [P] [US1] Add identical retry logic to `DeliverSmsNotificationJob` in `app/Modules/Notification/Application/Jobs/DeliverSmsNotificationJob.php` â€” same $tries, backoff, and failed() pattern as T048; audit action: `notification.permanently_failed` with channel: 'sms'
- [X] T050 [P] [US1] Add identical retry logic to `DeliverEmailNotificationJob` in `app/Modules/Notification/Application/Jobs/DeliverEmailNotificationJob.php` â€” same pattern; audit action: `notification.permanently_failed` with channel: 'email'
- [X] T051 [US1] Implement `InAppNotificationService` in `app/Modules/Notification/Application/Services/InAppNotificationService.php` â€” `listForUser(string $userId, int $perPage, int $page, bool $unreadOnly, ?string $category): LengthAwarePaginator`: query `NotificationModel` where `recipient_id=$userId`; filter `read_at IS NULL` if `unreadOnly`; filter by category if provided; order `created_at DESC`; `getUnreadCount(string $userId): int`: count where `recipient_id=$userId AND read_at IS NULL`; `markRead(string $userId, ?array $ids, bool $markAll): int`: if `$ids`: update `read_at=now()` where `id IN $ids AND recipient_id=$userId AND read_at IS NULL`; if `$markAll`: update all unread for user; return count; `findForUser(string $userId, string $id): NotificationModel`: `NotificationModel::where('id', $id)->where('recipient_id', $userId)->firstOrFail()` (Laravel throws ModelNotFoundException â†’ 404)
- [X] T052 [US1] Create `InAppNotificationController` in `app/Modules/Notification/Infrastructure/Http/Controllers/InAppNotificationController.php` â€” `index(Request $request)`: validate query params, call `InAppNotificationService::listForUser()`, return `NotificationSummaryResource::collection()`; `unreadCount()`: return `{data: {unread_count: int}}`; `show(string $id)`: call `findForUser()`, return `NotificationDetailResource`; `markRead(MarkNotificationsReadRequest $request)`: call `markRead()`, return `{data: {marked_count: int}}`; all authorize `notifications.inbox.read`; all scope to `auth()->id()`
- [X] T053 [P] [US1] Create `MarkNotificationsReadRequest` in `app/Modules/Notification/Infrastructure/Http/Requests/MarkNotificationsReadRequest.php` â€” `rules()`: `ids` nullable array of UUIDs max 100; `mark_all` nullable boolean; custom `withValidator`: must provide `ids` OR `mark_all=true`, not both, not neither
- [X] T054 [P] [US1] Create `NotificationSummaryResource` in `app/Modules/Notification/Infrastructure/Http/Resources/NotificationSummaryResource.php` â€” maps to `{id, notification_type, category, subject, body, read: (bool) read_at !== null, created_at}`; create `NotificationDetailResource` extending summary adding `mandatory` field
- [X] T055 [US1] Register inbox routes under `auth:sanctum` in `app/Modules/Notification/Providers/NotificationServiceProvider.php` or `routes/api.php`: `GET /v1/notifications/inbox` â†’ `InAppNotificationController@index`; `GET /v1/notifications/inbox/unread-count` â†’ `@unreadCount`; `GET /v1/notifications/inbox/{id}` â†’ `@show`; `PATCH /v1/notifications/inbox/read` â†’ `@markRead`
- [X] T056 [US1] Unit test: retry flow â€” mock `PushChannelInterface::send()` to throw `RuntimeException` on first call, return success on second; dispatch job twice; assert `attempt_count=2`, `status=delivered`, no second `Notification` row created in `tests/Unit/Notification/Application/DeliverPushNotificationJobTest.php`
- [X] T057 [US1] Unit test: permanently failed â€” mock `PushChannelInterface::send()` to always throw; set `$tries=2`; dispatch twice; assert `status=permanently_failed`; assert `AuditServiceInterface::record` called with `notification.permanently_failed`; assert `Log::error` called in `tests/Unit/Notification/Application/DeliverPushNotificationJobTest.php`
- [X] T058 [US1] Unit test: token invalidation â€” mock `PushChannelInterface::send()` to return `PushDeliveryResult(success:false, tokenInvalid:true)`; dispatch once; assert `DeviceRegistryInterface::invalidateToken()` called; assert `status=failed`; assert job does NOT throw (no retry) in `tests/Unit/Notification/Application/DeliverPushNotificationJobTest.php`
- [X] T059 [US1] Unit test: `InAppNotificationService` â€” `listForUser` returns only user's notifications; `getUnreadCount` = count of `read_at IS NULL`; `markRead([id])` sets `read_at` on that id only; `markRead(markAll=true)` sets all; `findForUser` for another user's id throws ModelNotFoundException in `tests/Unit/Notification/Application/InAppNotificationServiceTest.php`
- [X] T060 [US1] Integration test: inbox API â€” seed 3 notifications for user; `GET /inbox` returns all 3; `GET /inbox/unread-count` returns 3; `PATCH /inbox/read` with 1 id returns `marked_count=1`; `GET /inbox/unread-count` returns 2; `GET /inbox/{id}` for notification owned by other user returns 404 in `tests/Integration/Notification/InboxApiTest.php`

**Checkpoint**: US1 complete â€” retry/backoff live on all 3 external channels; permanent failure audited; in-app inbox API accessible.

---

## Phase 6: User Story 2 â€” Notification Preferences (Priority: P1)

**Goal**: Users can configure per-category channel preferences. Mandatory categories (financial, security) cannot be disabled. Marketing defaults to opted-out. Preference changes are persisted and respected on next dispatch.

**Independent Test**: PUT `/v1/notifications/preferences` disabling SMS for operational â†’ dispatch operational notification â†’ no SMS delivery row. Attempt to remove `financial` â†’ 422 `MANDATORY_CATEGORY_REQUIRED`. GET preferences returns current state. (quickstart SC-4, SC-5)

- [X] T061 [US2] Add `updateForUser()` to `NotificationPreferenceService` in `app/Modules/Notification/Application/Services/NotificationPreferenceService.php` â€” **`getOrCreateForUser()` already implemented in T024 (Foundation); do not re-implement it**; add only: `updateForUser(string $userId, array $payload): NotificationPreferenceModel`: load preference via `getOrCreateForUser($userId)`; for each non-empty `*_enabled_categories` array in payload, validate `financial` and `security` are present (throw `MandatoryNotificationCannotBeDisabledException` if not); update fields; `$preference->save()`; audit `notification.preference_updated`; if `marketing_opted_in` flipped to true: audit `notification.marketing_opted_in`; if flipped to false: audit `notification.marketing_opted_out`; return updated model
- [X] T062 [US2] Create `NotificationPreferenceController` in `app/Modules/Notification/Infrastructure/Http/Controllers/NotificationPreferenceController.php` â€” `show(): JsonResponse`: `NotificationPreferenceService::getOrCreateForUser(auth()->id())` â†’ `NotificationPreferenceResource`; `update(UpdateNotificationPreferenceRequest $request): JsonResponse`: `NotificationPreferenceService::updateForUser(auth()->id(), $request->validated())` â†’ `NotificationPreferenceResource`; handle `MandatoryNotificationCannotBeDisabledException` â†’ 422 with `MANDATORY_CATEGORY_REQUIRED`; both methods require `notifications.preferences.manage`
- [X] T063 [P] [US2] Create `UpdateNotificationPreferenceRequest` in `app/Modules/Notification/Infrastructure/Http/Requests/UpdateNotificationPreferenceRequest.php` â€” `rules()`: `push_enabled_categories` nullable array of `in:financial,security,operational,marketing`; `sms_enabled_categories` same; `email_enabled_categories` same; `marketing_opted_in` nullable boolean; custom `withValidator`: for each non-empty categories array submitted, assert it includes both 'financial' and 'security'; if not, fail with message mapped to `MANDATORY_CATEGORY_REQUIRED`
- [X] T064 [P] [US2] Create `NotificationPreferenceResource` in `app/Modules/Notification/Infrastructure/Http/Resources/NotificationPreferenceResource.php` â€” maps to `{user_id, push_enabled_categories, sms_enabled_categories, email_enabled_categories, marketing_opted_in, updated_at}`
- [X] T065 [US2] Register preference routes under `auth:sanctum` in `app/Modules/Notification/Providers/NotificationServiceProvider.php` or `routes/api.php`: `GET /v1/notifications/preferences` â†’ `NotificationPreferenceController@show`; `PUT /v1/notifications/preferences` â†’ `@update`
- [X] T066 [US2] Unit test: `NotificationPreferenceService::updateForUser()` â€” submit `push_enabled_categories = ['operational']` (missing financial) â†’ throws `MandatoryNotificationCannotBeDisabledException`; valid update persists and returns model with new values; `marketing_opted_in` flip to true audits `notification.marketing_opted_in` in `tests/Unit/Notification/Application/NotificationPreferenceServiceTest.php`
- [X] T067 [US2] Integration test: PUT `/v1/notifications/preferences` with valid body persists + GET returns updated values; PUT with `push_enabled_categories = ['operational']` returns 422 with `MANDATORY_CATEGORY_REQUIRED`; new user gets default preferences on first GET in `tests/Integration/Notification/PreferenceApiTest.php`

**Checkpoint**: US2 complete â€” all 4 user stories implemented; full notification pipeline live end-to-end.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI documentation, static analysis, quickstart validation, remaining unit tests.

- [X] T068 Write OpenAPI 3.1.0 spec for all 6 notification endpoints in `openapi/v1/notifications.yaml` â€” cover `GET/PUT /v1/notifications/preferences` and `GET /v1/notifications/inbox`, `GET /v1/notifications/inbox/unread-count`, `GET /v1/notifications/inbox/{id}`, `PATCH /v1/notifications/inbox/read`; include reusable schemas: `NotificationPreference`, `NotificationSummary`, `NotificationDetail`, `MarkReadRequest`, `UnreadCountResponse`, `ErrorResponse`; follow platform error envelope standard
- [X] T069 [P] Unit test: `NotificationTemplateService` â€” render with all variables present; render with missing variable â†’ `[Unknown]` substituted + `Log::warning` called; `TemplateNotFoundException` thrown for unknown key; `TemplateNotFoundException` thrown for inactive template in `tests/Unit/Notification/Application/NotificationTemplateServiceTest.php`
- [X] T070 [P] Integration test: idempotency under concurrent dispatch â€” dispatch 3 identical `QrPaymentCompletedEvent` jobs in parallel; assert exactly 1 `Notification` row per `(source_event_id, notification_type)` pair (UNIQUE constraint absorbs race); no unhandled exception in `tests/Integration/Notification/IdempotencyTest.php`
- [X] T071 [P] Integration test: retry succeeds on second attempt â€” configure `PushChannelInterface` binding in test container to `FailOnceLogPushChannel` (fails first call, succeeds on second); dispatch push notification; run queue twice; assert `attempt_count=2`, final `status=delivered` in `tests/Integration/Notification/RetryTest.php`
- [X] T072 [P] Run PHPStan level 8 on `app/Modules/Notification/` in Docker (`docker compose exec api bash -c "vendor/bin/phpstan analyse app/Modules/Notification --level=8"`) â€” resolve all reported errors
- [X] T073 Run quickstart SC-1 through SC-10 in Docker (`docker compose exec api bash`) â€” execute each scenario from `specs/017-notifications/quickstart.md`; record pass/fail per scenario
- [ ] T074 [P] Performance test: dispatch `QrPaymentCompletedEvent`, run queue jobs, measure elapsed time from event dispatch to `in_app` `NotificationDelivery.status = delivered`; assert median < 5000ms over 5 iterations â€” covers SC-E17-001 (5-second delivery SLA); implement in `tests/Performance/Notification/NotificationLatencyTest.php`; run in Docker: `docker compose exec api vendor/bin/phpunit tests/Performance/Notification/NotificationLatencyTest.php`
- [X] T075 [P] Wire metrics instrumentation per plan.md Observability section in `app/Modules/Notification/Application/Services/NotificationService.php` and `app/Modules/Notification/Application/Jobs/`: add `Metric::increment('notification.created', ['category' => $cmd->category->value])` in `NotificationService::createFromEvent()` after persist (T026); add `Metric::increment('notification.delivered', ['channel' => $channel, 'category' => $category])` in each delivery job's success path (T029â€“T032); add `Metric::increment('notification.permanently_failed', ['channel' => $channel])` in each `failed()` callback (T048â€“T050); add `Metric::increment('notification.retry', ['channel' => $channel])` in each delivery job before rethrowing on failure; add `Metric::increment('notification.failed', ['channel' => $channel, 'error_code' => $errorCode])` on channel failure path â€” these counters correspond exactly to the 5 metrics defined in plan.md Observability section
- [ ] T076 [P] Load test: SC-E17-002 delivery rate â€” seed 100 users each with a device token; dispatch 100 `QrPaymentCompletedEvent` events sequentially (simulating a bulk commission batch); record the timestamp when each `in_app` `NotificationDelivery.status` transitions to `delivered`; assert that at least 99 of the 100 in-app deliveries reach `delivered` within 30 seconds of their `Notification.created_at` timestamp â€” covers SC-E17-002 (99% mandatory delivery rate within 30s); implement in `tests/Performance/Notification/NotificationDeliveryRateTest.php`; run in Docker: `docker compose exec api vendor/bin/phpunit tests/Performance/Notification/NotificationDeliveryRateTest.php`; note: run Horizon worker during test (`php artisan horizon` in background) to exercise the actual queue dispatch path

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies â€” start immediately
- **Foundational (Phase 2)**: Depends on Setup (T001â€“T003) â€” BLOCKS all user story phases
- **US0 (Phase 3)**: Depends on Foundational complete (T004â€“T025)
- **US3 (Phase 4)**: Depends on US0 complete â€” refines `getEnabledChannels()` implemented in T043; uses delivery pipeline from T028
- **US1 (Phase 5)**: Depends on US0 complete â€” adds retry to delivery jobs created in T030â€“T032
- **US2 (Phase 6)**: Depends on US3 complete â€” adds CRUD to `NotificationPreferenceService` started in T043
- **Polish (Phase 7)**: Depends on US1 + US2 complete

### User Story Dependencies

- **US0 (P0)**: After Foundation only; no other story
- **US3 (P0)**: After US0 â€” refines existing delivery service and preference service
- **US1 (P1)**: After US0 â€” adds retry to the jobs US0 created; adds inbox API
- **US2 (P1)**: After US3 â€” extends the preference service US3 initialized

### Within Phase 2 (Foundational)

- T004 (schema) must precede T005â€“T009
- T005â€“T009 can run in parallel after T004
- T010â€“T013 (enums): all parallel, no dependencies
- T014â€“T017 (models): all parallel, depend on T005â€“T008 migrations
- T018â€“T020 (contracts/DTOs/exceptions): all parallel
- T021â€“T022 (adapters): parallel; T022 depends on T018 interface existing
- T023â€“T024 (services): parallel; depend on T016 (template model), T017 (preference model)
- T025 (ServiceProvider completion): depends on T021â€“T024

### Parallel Opportunities

All `[P]` marked tasks in each phase can run simultaneously. Key batches:
- T005â€“T009: migrations (after T004)
- T010â€“T013: enums
- T014â€“T017: models
- T018â€“T020: contracts, DTOs, exceptions
- T030â€“T032: base delivery jobs (after T028 service exists)
- T034â€“T038: 5 event listeners (after T033 establishes the pattern)
- T048â€“T050: retry additions to SMS/email jobs (after T048 push done as reference)
- T053â€“T054: request + resource classes
- T056â€“T059: unit tests (all independent)
- T069â€“T071: polish tests

---

## Parallel Example: US0 Event Listeners

```
# After T033 (QrPaymentCompletedListener pattern established), all 5 run in parallel:
T034: FundingCompletedListener    â€” different file, same pattern
T035: TransferCompletedListener   â€” different file, same pattern
T036: WithdrawalProcessedListener â€” different file, same pattern
T037: CommissionCreditedListener  â€” different file, same pattern
T038: BillPaymentCompletedListener â€” different file, same pattern
```

---

## Implementation Strategy

### MVP First (US0 Only)

1. Complete Phase 1: Setup (T001â€“T003)
2. Complete Phase 2: Foundational (T004â€“T025) â€” CRITICAL BLOCK
3. Complete Phase 3: US0 (T026â€“T042)
4. **STOP and VALIDATE**: quickstart SC-1 (payment â†’ in-app) + SC-2 (idempotency)
5. E17 is live for E16 QR payment notifications

### Incremental Delivery

1. Setup + Foundation â†’ skeleton ready
2. US0 â†’ financial events produce in-app notifications (MVP; log channel for push/SMS/email)
3. US3 â†’ mandatory override + marketing gate
4. US1 â†’ retry/backoff + inbox API
5. US2 â†’ user preference management API
6. Polish â†’ PHPStan clean + full SC-1â€“SC-10 validation

---

## Notes

- `[P]` = different files, no in-phase dependencies
- `NotificationPreferenceService` built incrementally: T024 (stub) â†’ T043 (getEnabledChannels full) â†’ T061 (CRUD methods)
- `LogPushChannel`, `LogSmsChannel`, `LogEmailChannel` are the shipping defaults; real adapters bound in E1000 without modifying E17
- In-app delivery (`DeliverInAppNotificationJob`) always succeeds â€” it is the unconditional fallback channel
- All retry config (`notifications.retry.max_attempts`, `notifications.retry.initial_delay_seconds`) must be read from `config()` keys backed by `ConfigurationServiceInterface`; never hard-coded
- T072 (PHPStan) and T073 (quickstart) require Docker; they do not block marking implementation RELEASE READY if other tasks pass
