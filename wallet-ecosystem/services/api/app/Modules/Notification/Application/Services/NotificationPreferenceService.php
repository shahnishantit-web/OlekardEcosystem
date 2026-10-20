<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationChannel;
use App\Modules\Notification\Domain\Exceptions\MandatoryNotificationCannotBeDisabledException;
use App\Modules\Notification\Infrastructure\Models\NotificationPreferenceModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Str;

class NotificationPreferenceService
{
    public function __construct(
        private readonly AuditServiceInterface       $audit,
        private readonly DeviceRegistryInterface     $deviceRegistry,
        private readonly UserContactServiceInterface $contactService,
    ) {}

    public function getOrCreateForUser(string $userId): NotificationPreferenceModel
    {
        return NotificationPreferenceModel::firstOrCreate(
            ['user_id' => $userId],
            [
                'id'                       => (string) Str::orderedUuid(),
                'push_enabled_categories'  => ['financial', 'security', 'operational'],
                'sms_enabled_categories'   => ['financial', 'security'],
                'email_enabled_categories' => ['security'],
                'marketing_opted_in'       => false,
            ]
        );
    }

    /**
     * Returns the enabled delivery channels for a given user, category, and mandatory flag.
     *
     * @return NotificationChannel[]
     */
    public function getEnabledChannels(
        string               $userId,
        NotificationCategory $category,
        bool                 $mandatory,
        ?string              $notificationId = null,
    ): array {
        if ($mandatory || $category->isMandatory()) {
            return $this->getMandatoryChannels($userId, $category, $notificationId);
        }

        if ($category === NotificationCategory::Marketing) {
            $preference = $this->getOrCreateForUser($userId);

            return $preference->marketing_opted_in
                ? $this->getPreferenceChannels($preference, $category)
                : [];
        }

        $preference = $this->getOrCreateForUser($userId);

        return $this->getPreferenceChannels($preference, $category);
    }

    /**
     * @return NotificationChannel[]
     */
    private function getMandatoryChannels(
        string               $userId,
        NotificationCategory $category,
        ?string              $notificationId,
    ): array {
        $preference = $this->getOrCreateForUser($userId);
        $available  = [];

        // In-app is always available
        $available[] = NotificationChannel::InApp;

        // Push: only if at least one device token registered
        if (! empty($this->deviceRegistry->getActiveTokensForUser($userId))) {
            $available[] = NotificationChannel::Push;
        }

        // SMS: only if phone number on record
        if ($this->contactService->getPhoneForUser($userId) !== null) {
            $available[] = NotificationChannel::Sms;
        }

        // Email: only if email address on record
        if ($this->contactService->getEmailForUser($userId) !== null) {
            $available[] = NotificationChannel::Email;
        }

        // Check if stored preference would have excluded any available channel
        $preferenceChannels = $this->getPreferenceChannels($preference, $category);
        $overrideApplied    = false;

        foreach ($available as $channel) {
            if (! in_array($channel, $preferenceChannels, true)) {
                $overrideApplied = true;
                break;
            }
        }

        if ($overrideApplied) {
            $this->audit->record(new AuditEventData(
                action:        'notification.mandatory_override_applied',
                actorType:     'system',
                resourceType:  'notification',
                correlationId: $notificationId ?? 'unknown',
                outcome:       'success',
                resourceId:    $notificationId,
                metadata:      [
                    'category' => $category->value,
                    'userId'   => $userId,
                ],
            ));
        }

        return $available;
    }

    /**
     * @return NotificationChannel[]
     */
    private function getPreferenceChannels(NotificationPreferenceModel $preference, NotificationCategory $category): array
    {
        $channels = [];

        if (in_array($category->value, $preference->push_enabled_categories ?? [], true)) {
            $channels[] = NotificationChannel::Push;
        }

        if (in_array($category->value, $preference->sms_enabled_categories ?? [], true)) {
            $channels[] = NotificationChannel::Sms;
        }

        if (in_array($category->value, $preference->email_enabled_categories ?? [], true)) {
            $channels[] = NotificationChannel::Email;
        }

        // In-app is always available
        $channels[] = NotificationChannel::InApp;

        return array_unique($channels, SORT_REGULAR);
    }

    public function updateForUser(string $userId, array $payload): NotificationPreferenceModel
    {
        $preference = $this->getOrCreateForUser($userId);
        $oldMarketing = $preference->marketing_opted_in;

        foreach (['push_enabled_categories', 'sms_enabled_categories', 'email_enabled_categories'] as $field) {
            if (! isset($payload[$field]) || empty($payload[$field])) {
                continue;
            }

            $categories = $payload[$field];
            if (! in_array('financial', $categories, true) || ! in_array('security', $categories, true)) {
                throw new MandatoryNotificationCannotBeDisabledException(
                    "The '{$field}' must include both 'financial' and 'security' categories."
                );
            }
        }

        if (isset($payload['push_enabled_categories'])) {
            $preference->push_enabled_categories = $payload['push_enabled_categories'];
        }
        if (isset($payload['sms_enabled_categories'])) {
            $preference->sms_enabled_categories = $payload['sms_enabled_categories'];
        }
        if (isset($payload['email_enabled_categories'])) {
            $preference->email_enabled_categories = $payload['email_enabled_categories'];
        }
        if (array_key_exists('marketing_opted_in', $payload)) {
            $preference->marketing_opted_in = (bool) $payload['marketing_opted_in'];
        }

        $preference->save();

        $this->audit->record(new AuditEventData(
            action:        'notification.preference_updated',
            actorType:     'user',
            resourceType:  'notification_preference',
            correlationId: $userId,
            outcome:       'success',
            resourceId:    $preference->id,
            metadata:      ['user_id' => $userId],
        ));

        $newMarketing = $preference->marketing_opted_in;
        if ($oldMarketing !== $newMarketing) {
            $action = $newMarketing
                ? 'notification.marketing_opted_in'
                : 'notification.marketing_opted_out';

            $this->audit->record(new AuditEventData(
                action:        $action,
                actorType:     'user',
                resourceType:  'notification_preference',
                correlationId: $userId,
                outcome:       'success',
                resourceId:    $preference->id,
                metadata:      ['user_id' => $userId],
            ));
        }

        return $preference;
    }
}
