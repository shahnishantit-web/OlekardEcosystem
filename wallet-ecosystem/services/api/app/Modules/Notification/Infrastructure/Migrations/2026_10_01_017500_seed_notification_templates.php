<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'notification';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    public function up(): void
    {
        $now = $this->now();

        $templates = [
            [
                'key'               => 'payment.qr.received',
                'category'          => 'financial',
                'subject_template'  => 'Payment received: {{amount}} {{currency}}',
                'body_template'     => 'You received a payment of {{amount}} {{currency}} from customer {{customer_ref}}. Reference: {{payment_ref}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Payment amount', 'currency' => 'Currency code', 'customer_ref' => 'Customer reference', 'payment_ref' => 'Payment reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.qr.sent',
                'category'          => 'financial',
                'subject_template'  => 'Payment sent: {{amount}} {{currency}} to {{merchant_name}}',
                'body_template'     => 'You paid {{amount}} {{currency}} to {{merchant_name}}. Reference: {{payment_ref}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Payment amount', 'currency' => 'Currency code', 'merchant_name' => 'Merchant name', 'payment_ref' => 'Payment reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.funding.received',
                'category'          => 'financial',
                'subject_template'  => 'Wallet funded: {{amount}} {{currency}}',
                'body_template'     => 'Your wallet has been funded with {{amount}} {{currency}} via {{source}}. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Funded amount', 'currency' => 'Currency code', 'source' => 'Funding source', 'reference' => 'Transaction reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.transfer.received',
                'category'          => 'financial',
                'subject_template'  => 'Transfer received: {{amount}} {{currency}}',
                'body_template'     => 'You received {{amount}} {{currency}} from {{sender_ref}}. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Transfer amount', 'currency' => 'Currency code', 'sender_ref' => 'Sender reference', 'reference' => 'Transfer reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.transfer.sent',
                'category'          => 'financial',
                'subject_template'  => 'Transfer sent: {{amount}} {{currency}}',
                'body_template'     => 'You sent {{amount}} {{currency}} to {{recipient_ref}}. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Transfer amount', 'currency' => 'Currency code', 'recipient_ref' => 'Recipient reference', 'reference' => 'Transfer reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.withdrawal.processed',
                'category'          => 'financial',
                'subject_template'  => 'Withdrawal processed: {{amount}} {{currency}}',
                'body_template'     => 'Your withdrawal of {{amount}} {{currency}} has been processed. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Withdrawal amount', 'currency' => 'Currency code', 'reference' => 'Withdrawal reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.commission.credited',
                'category'          => 'financial',
                'subject_template'  => 'Commission credited: {{amount}} {{currency}}',
                'body_template'     => 'Your commission of {{amount}} {{currency}} for period {{period}} has been credited to your account. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Commission amount', 'currency' => 'Currency code', 'period' => 'Commission period', 'reference' => 'Payout reference']),
                'active'            => true,
            ],
            [
                'key'               => 'payment.bill.paid',
                'category'          => 'financial',
                'subject_template'  => 'Bill payment confirmed: {{amount}} {{currency}} to {{biller}}',
                'body_template'     => 'Your payment of {{amount}} {{currency}} to {{biller}} has been confirmed. Reference: {{reference}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['amount' => 'Payment amount', 'currency' => 'Currency code', 'biller' => 'Biller name', 'reference' => 'Payment reference']),
                'active'            => true,
            ],
            [
                'key'               => 'security.new_device',
                'category'          => 'security',
                'subject_template'  => 'New device login detected',
                'body_template'     => 'A new {{platform}} device "{{device_name}}" was used to access your account on {{occurred_at}}. If this was not you, please contact support immediately.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['device_name' => 'Device name', 'platform' => 'Device platform', 'occurred_at' => 'Event timestamp']),
                'active'            => true,
            ],
            [
                'key'               => 'security.password_changed',
                'category'          => 'security',
                'subject_template'  => 'Your PIN was changed',
                'body_template'     => 'Your transaction PIN was changed on {{occurred_at}}. If you did not make this change, contact support at {{support_ref}}.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['occurred_at' => 'Change timestamp', 'support_ref' => 'Support reference']),
                'active'            => true,
            ],
            [
                'key'               => 'security.suspicious_activity',
                'category'          => 'security',
                'subject_template'  => 'Suspicious activity detected on your account',
                'body_template'     => 'Suspicious activity was detected on your account: {{detail}}. This occurred on {{occurred_at}}. Contact support at {{support_ref}} if you need assistance.',
                'supported_channels' => json_encode(['push', 'sms', 'email', 'in_app']),
                'variables'         => json_encode(['detail' => 'Activity detail', 'occurred_at' => 'Event timestamp', 'support_ref' => 'Support reference']),
                'active'            => true,
            ],
        ];

        foreach ($templates as $template) {
            DB::connection('notification')->table('notification.notification_templates')->insertOrIgnore(
                array_merge($template, [
                    'id'         => (string) Str::orderedUuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        // Seed notification permissions into identity schema (idempotent)
        $permissions = [
            ['slug' => 'notifications.preferences.manage', 'display_name' => 'Manage Notification Preferences'],
            ['slug' => 'notifications.inbox.read',         'display_name' => 'Read Notification Inbox'],
            ['slug' => 'notifications.admin.send',         'display_name' => 'Send Notifications (Admin)'],
        ];

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'notification',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        // Assign inbox.read and preferences.manage to customer and agent roles
        $customerSlugs = ['notifications.preferences.manage', 'notifications.inbox.read'];
        $agentSlugs    = ['notifications.preferences.manage', 'notifications.inbox.read'];
        $adminSlugs    = ['notifications.preferences.manage', 'notifications.inbox.read', 'notifications.admin.send'];

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        foreach (['customer' => $customerSlugs, 'agent' => $agentSlugs, 'admin' => $adminSlugs] as $roleSlug => $slugs) {
            $roleId = DB::connection('identity')->table('identity.roles')
                ->where('slug', $roleSlug)
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($slugs as $slug) {
                if (! isset($allPermIds[$slug])) {
                    continue;
                }
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $roleId,
                    'permission_id'       => $allPermIds[$slug],
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'payment.qr.received', 'payment.qr.sent', 'payment.funding.received',
            'payment.transfer.received', 'payment.transfer.sent', 'payment.withdrawal.processed',
            'payment.commission.credited', 'payment.bill.paid',
            'security.new_device', 'security.password_changed', 'security.suspicious_activity',
        ];
        DB::connection('notification')->table('notification.notification_templates')
            ->whereIn('key', $keys)
            ->delete();

        $slugs = ['notifications.preferences.manage', 'notifications.inbox.read', 'notifications.admin.send'];
        $permissionIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)->pluck('id');

        DB::connection('identity')->table('identity.role_permissions')
            ->whereIn('permission_id', $permissionIds)->delete();
        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)->where('is_system_permission', true)->delete();
    }
};
