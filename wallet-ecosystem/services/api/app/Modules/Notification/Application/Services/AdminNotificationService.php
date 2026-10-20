<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AdminNotificationService
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function templates(): array
    {
        return NotificationTemplateModel::orderBy('key')->get()->toArray();
    }

    public function preview(array $input): array
    {
        $replace = static fn (string $text) => preg_replace_callback('/\{\{(\w+)\}\}/', static fn (array $m) => (string) ($input['variables'][$m[1]] ?? '[Unknown]'), $text);

        return ['subject' => $replace($input['subject_template']), 'body' => $replace($input['body_template'])];
    }

    public function edit(array $input, string $actor, string $correlation): array
    {
        return DB::connection('notification')->transaction(function () use ($input, $actor, $correlation): array {
            $template = NotificationTemplateModel::whereKey($input['id'])->lockForUpdate()->firstOrFail();
            abort_if($template->revision !== $input['revision'], 409, 'Template changed. Reload before saving.');
            $template->fill(array_intersect_key($input, array_flip(['subject_template', 'body_template', 'language'])));
            $template->revision++;
            $template->save();
            $this->record('notification.template_updated', $template->id, $actor, $correlation, ['revision' => $template->revision]);

            return $template->toArray();
        });
    }

    public function test(array $input, string $actor, string $correlation): array
    {
        return DB::connection('notification')->transaction(function () use ($input, $actor, $correlation): array {
            $template = NotificationTemplateModel::findOrFail($input['id']);
            $notification = app(NotificationService::class)->createFromEvent(new CreateNotificationCommand($input['request_id'], 'ADMIN_NOTIFICATION_TEST', $template->key, $template->category, $template->category->isMandatory(), $actor, $input['variables'] ?? [], $correlation));
            abort_if($notification->recipient_id !== $actor, 409, 'Request identifier already in use.');
            $this->record('notification.template_test_requested', $template->id, $actor, $correlation, ['notification_id' => $notification->id]);

            return ['id' => $notification->id, 'status' => 'queued'];
        });
    }

    public function log(array $filters): array
    {
        $query = NotificationDeliveryModel::query()->with('notification')->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (! empty($filters['status'])) {
            $statuses = match ($filters['status']) {
                'failed' => ['failed', 'permanently_failed'],'sent' => ['sent', 'delivered'],default => [$filters['status']]
            };
            $query->whereIn('status', $statuses);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00+00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay());
        }
        $page = $query->paginate(25, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => array_map(static fn ($d) => ['id' => $d->id, 'recipient' => $d->notification?->recipient_id, 'channel' => $d->channel->value, 'event' => $d->notification?->source_event_type,
            'status' => $d->status->value, 'timestamp' => $d->created_at?->toIso8601String(), 'last_attempt_at' => $d->last_attempt_at?->toIso8601String(), 'attempt_count' => $d->attempt_count], $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    public function retry(string $id, string $actor, string $correlation): array
    {
        return DB::connection('notification')->transaction(function () use ($id, $actor, $correlation): array {
            DB::connection('notification')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['notification-delivery:'.$id]);
            $delivery = NotificationDeliveryModel::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($delivery->status->value, ['failed', 'permanently_failed'], true), 409, 'Only failed deliveries can be retried.');
            $delivery->status = 'queued';
            $delivery->next_retry_at = null;
            $delivery->save();
            $this->record('notification.delivery_retry_requested', $id, $actor, $correlation, ['channel' => $delivery->channel->value]);
            $this->outbox('NOTIFICATION_DELIVERY_RETRY', ['delivery_id' => $id, 'channel' => $delivery->channel->value, 'mandatory' => $delivery->notification?->mandatory ?? false]);

            return ['id' => $id, 'status' => 'queued'];
        });
    }

    public function updateRule(array $input, string $actor, string $correlation): array
    {
        return DB::connection('notification')->transaction(function () use ($input, $actor, $correlation): array {
            $template = NotificationTemplateModel::whereKey($input['template_id'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($input['channel'], $template->supported_channels, true), 422, 'Unsupported template channel.');
            abort_if($template->category->isMandatory() && ! $input['enabled'], 422, 'Mandatory notifications cannot be disabled.');
            $query = DB::connection('notification')->table('notification.admin_rules')->where('template_id', $template->id)->where('channel', $input['channel']);
            $existing = $query->first();
            abort_if(($existing?->revision ?? 0) !== $input['revision'], 409, 'Rule changed. Reload before saving.');
            $revision = ($existing?->revision ?? 0) + 1;
            $values = ['enabled' => $input['enabled'], 'revision' => $revision, 'updated_at' => now()];
            if ($existing) {
                $query->update($values);
            } else {
                DB::connection('notification')->table('notification.admin_rules')->insert($values + ['template_id' => $template->id, 'channel' => $input['channel'], 'created_at' => now()]);
            }
            $payload = ['template_id' => $template->id, 'event' => $template->key, 'channel' => $input['channel'], 'enabled' => $input['enabled'], 'revision' => $revision, 'actor_id' => $actor, 'correlation_id' => $correlation];
            $this->record('notification.rule_updated', $template->id, $actor, $correlation, $payload);
            $this->outbox('NOTIFICATION_RULE_UPDATED', $payload);

            return $payload;
        });
    }

    private function record(string $action, string $resource, string $actor, string $correlation, array $metadata): void
    {
        DB::connection('notification')->table('notification.admin_actions')->insert(['id' => (string) Uuid::uuid7(), 'actor_id' => $actor, 'action' => $action, 'resource_id' => $resource, 'correlation_id' => $correlation, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->audit->record(new AuditEventData(action: $action, actorType: 'admin', resourceType: 'notification_admin', correlationId: $correlation, outcome: 'success', resourceId: $resource, actorId: $actor, metadata: $metadata));
    }

    private function outbox(string $name,array $payload): void
    {
        $id = (string) Uuid::uuid7();
        $payload['event_id'] = $id;
        DB::connection('notification')->table('notification.admin_outbox')->insert(['id' => $id, 'event_name' => $name, 'payload' => json_encode($payload,JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
