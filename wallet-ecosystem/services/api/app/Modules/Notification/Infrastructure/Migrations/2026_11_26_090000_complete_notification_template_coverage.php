<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        $now = now();
        $allChannels = ['in_app', 'push', 'sms', 'email'];
        $operationalChannels = ['in_app', 'push', 'email'];
        $financialVariables = [
            'amount_minor' => 'Amount in minor currency units',
            'currency_code' => 'ISO currency code',
        ];
        $templates = [
            [
                'key' => 'payment_confirmed', 'category' => 'financial',
                'subject_template' => 'Payment received',
                'body_template' => 'Your payment of {{amount_minor}} minor units {{currency_code}} was successful.',
                'channels' => $allChannels, 'variables' => $financialVariables,
            ],
            [
                'key' => 'payment_failed', 'category' => 'financial',
                'subject_template' => 'Payment not completed',
                'body_template' => 'Your payment could not be completed. Check the payment status in OleKard before trying again.',
                'channels' => $allChannels, 'variables' => [],
            ],
            [
                'key' => 'payment_reversed', 'category' => 'financial',
                'subject_template' => 'Payment reversed',
                'body_template' => 'A payment of {{amount_minor}} minor units {{currency_code}} was reversed. Review your transaction history for the latest status.',
                'channels' => $allChannels, 'variables' => $financialVariables,
            ],
            [
                'key' => 'refund_confirmed', 'category' => 'financial',
                'subject_template' => 'Refund processed',
                'body_template' => 'Your refund of {{amount_minor}} minor units {{currency_code}} has been confirmed.',
                'channels' => $allChannels, 'variables' => $financialVariables,
            ],
            [
                'key' => 'payment.receipt.payment_confirmed', 'category' => 'financial',
                'subject_template' => 'Payment confirmed: {{amount}} {{currency}}',
                'body_template' => 'Your payment of {{amount}} {{currency}} is confirmed. Provider: {{provider}}. Reference: {{payment_reference}}.',
                'channels' => $allChannels,
                'variables' => ['amount' => 'Payment amount', 'currency' => 'Currency code', 'provider' => 'Payment provider', 'payment_reference' => 'Payment reference'],
            ],
            [
                'key' => 'payment.receipt.refund_confirmed', 'category' => 'financial',
                'subject_template' => 'Refund confirmed: {{refund_amount}} {{currency}}',
                'body_template' => 'A {{is_full_refund}} refund of {{refund_amount}} {{currency}} for payment {{payment_reference}} has been confirmed.',
                'channels' => $allChannels,
                'variables' => ['refund_amount' => 'Refund amount', 'currency' => 'Currency code', 'payment_reference' => 'Payment reference', 'is_full_refund' => 'Whether the payment was fully refunded'],
            ],
            [
                'key' => 'transaction.reversed', 'category' => 'financial',
                'subject_template' => 'Transaction reversed',
                'body_template' => 'Transaction {{transaction_id}} for {{amount}} {{currency}} was reversed at {{reversed_at}}.',
                'channels' => $allChannels,
                'variables' => ['transaction_id' => 'Transaction reference', 'module_type' => 'Transaction type', 'amount' => 'Amount', 'currency' => 'Currency code', 'reversed_at' => 'Reversal time'],
            ],
            [
                'key' => 'events.event_cancelled', 'category' => 'operational',
                'subject_template' => 'Event cancelled: {{event_title}}',
                'body_template' => 'The event {{event_title}}, scheduled for {{event_starts_at}}, has been cancelled. Check OleKard for ticket and refund updates.',
                'channels' => $allChannels,
                'variables' => ['event_title' => 'Event title', 'event_starts_at' => 'Scheduled start time'],
            ],
            [
                'key' => 'events.registration.confirmed', 'category' => 'operational',
                'subject_template' => 'You are registered for {{event_title}}',
                'body_template' => 'Your {{allocation_type}} registration for {{event_title}} is confirmed. Event time: {{event_starts_at}}. Your ticket is available in My Event Tickets.',
                'channels' => $allChannels,
                'variables' => ['event_title' => 'Event title', 'event_starts_at' => 'Event start time', 'allocation_type' => 'Free or complimentary'],
            ],
            [
                'key' => 'events.paid_tickets.issued', 'category' => 'operational',
                'subject_template' => 'Your tickets for {{event_title}} are ready',
                'body_template' => 'Payment is confirmed and {{ticket_count}} ticket(s) for {{event_title}} have been issued. Event time: {{event_starts_at}}. View them in My Event Tickets.',
                'channels' => $allChannels,
                'variables' => ['event_title' => 'Event title', 'event_starts_at' => 'Event start time', 'ticket_count' => 'Number of tickets issued'],
            ],
            [
                'key' => 'events.invitation.received', 'category' => 'operational',
                'subject_template' => 'You are invited to {{event_title}}',
                'body_template' => 'You have an invitation for {{event_title}}. Review it in My Tickets → Invitations. Invitation expires {{expires_at}}.',
                'channels' => $allChannels,
                'variables' => ['event_title' => 'Event title', 'expires_at' => 'Invitation expiry time'],
            ],
            [
                'key' => 'report.job.completed', 'category' => 'operational',
                'subject_template' => 'Your {{report_type}} report is ready',
                'body_template' => 'Report job {{job_id}} completed with {{row_count}} rows as of {{as_of_at}}.',
                'channels' => $operationalChannels,
                'variables' => ['report_type' => 'Report type', 'row_count' => 'Rows produced', 'as_of_at' => 'Report timestamp', 'job_id' => 'Report job reference'],
            ],
            [
                'key' => 'report.job.failed', 'category' => 'operational',
                'subject_template' => 'Your {{report_type}} report could not be generated',
                'body_template' => 'Report job {{job_id}} failed: {{failure_reason}}. You can retry it from Reports.',
                'channels' => $operationalChannels,
                'variables' => ['report_type' => 'Report type', 'failure_reason' => 'Failure reason', 'job_id' => 'Report job reference'],
            ],
            [
                'key' => 'integration.webhook.dead_lettered', 'category' => 'operational',
                'subject_template' => 'Integration event requires attention',
                'body_template' => 'Webhook {{webhook_event_id}} from {{provider_slug}} ({{event_type}}) failed after {{retry_count}} attempts and needs review.',
                'channels' => $operationalChannels,
                'variables' => ['webhook_event_id' => 'Webhook event reference', 'provider_slug' => 'Provider', 'event_type' => 'Event type', 'retry_count' => 'Retry count'],
            ],
            [
                'key' => 'security.account_frozen_aml', 'category' => 'security',
                'subject_template' => 'Important account security update',
                'body_template' => 'A restriction was placed on your account at {{occurred_at}}. Reference: {{support_ref}}. Contact OleKard support if you need help.',
                'channels' => $allChannels,
                'variables' => ['occurred_at' => 'Restriction time', 'support_ref' => 'Support reference'],
            ],
        ];

        foreach ($templates as $template) {
            DB::connection('notification')->table('notification.notification_templates')->insertOrIgnore([
                'id' => (string) Str::orderedUuid(),
                'key' => $template['key'],
                'category' => $template['category'],
                'subject_template' => $template['subject_template'],
                'body_template' => $template['body_template'],
                'supported_channels' => json_encode($template['channels'], JSON_THROW_ON_ERROR),
                'variables' => json_encode($template['variables'], JSON_THROW_ON_ERROR),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::connection('notification')->table('notification.notification_templates')
            ->where('key', 'funeral.collection.opened')
            ->update(['supported_channels' => json_encode($allChannels, JSON_THROW_ON_ERROR), 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Keep templates and any administrator edits when rolling back unrelated changes.
    }
};
