<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Services;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use App\Modules\Ops\Domain\Contracts\IntegrationHealthHistoryInterface;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AdminIntegrationService
{
    public function __construct(private readonly IntegrationHealthHistoryInterface $health, private readonly AuditServiceInterface $audit) {}

    public function clients(?string $partner = null): array
    {
        $query = ApiClientModel::query()->orderByDesc('created_at')->orderBy('id');
        if ($partner) {
            $query->where('integration_id', $partner);
        }
        $page = $query->paginate(25);

        return ['data' => array_map(fn ($c) => $this->safeClient($c), $page->items()), 'meta' => $this->meta($page)];
    }

    public function safeClient(ApiClientModel $client): array
    {
        return ['id' => $client->id, 'name' => $client->name, 'api_key_prefix' => substr($client->api_key, 0, 8), 'status' => $client->revoked_at ? 'revoked' : $client->status->value, 'permissions' => $client->permissions, 'ip_whitelist' => $client->ip_whitelist ?? [], 'integration_id' => $client->integration_id, 'rate_limit_per_minute' => $client->rate_limit_per_minute, 'last_used_at' => $client->last_used_at, 'revoked_at' => $client->revoked_at];
    }

    public function validatePartnerAndPermissions(array $input): void
    {
        abort_unless(IntegrationModel::whereKey($input['integration_id'])->where('status', 'active')->exists(), 422, 'Select an active registered partner.');
        $permissions = array_unique($input['permissions'] ?? []);
        $count = DB::connection('identity')->table('identity.permissions')->whereIn('slug', $permissions)->count();
        abort_if($count !== count($permissions), 422, 'Unknown permission.');
    }

    public function revoke(string $id, string $actor, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($id, $actor, $correlation): array {
            $client = ApiClientModel::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $client->revoked_at) {
                app(ApiClientApplicationService::class)->updateStatus($id, ApiClientStatus::Inactive, $actor, $correlation);
                $client->status = 'inactive';
                $client->revoked_at = now();
                $client->save();
                $this->record('integration.api_client.revoked', $id, $actor, $correlation, []);
            }

            return $this->safeClient($client);
        });
    }

    public function providers(?string $type = null): array
    {
        $query = IntegrationModel::orderBy('display_name')->orderBy('id');
        if ($type) {
            $query->where('provider_type', $type);
        }$page = $query->paginate(25);

        return ['data' => array_map(fn ($p) => ['id' => $p->id, 'provider_type' => $p->provider_type->value, 'provider_slug' => $p->provider_slug, 'display_name' => $p->display_name, 'status' => $p->status->value, 'is_default' => $p->is_default] + array_intersect_key($this->health->history($p->id), array_flip(['health', 'last_checked_at'])), $page->items()), 'meta' => $this->meta($page)];
    }

    public function health(string $id): array
    {
        $partner = IntegrationModel::findOrFail($id);

        return ['integration_id' => $id, 'display_name' => $partner->display_name] + $this->health->history($id);
    }

    public function deliveries(array $filters): array
    {
        $query = WebhookEventModel::query()->withCount('processingAttempts')->withMax('processingAttempts', 'attempted_at')->orderByDesc('received_at')->orderBy('id');
        if (! empty($filters['status'])) {
            $query->whereIn('status', match ($filters['status']) {
                'delivered' => ['processed'],'failed' => ['failed', 'dead_letter'],'retrying' => ['received', 'processing']
            });
        }
        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        $page = $query->paginate(25);

        return ['data' => array_map(fn ($e) => ['id' => $e->id, 'event' => $e->event_type, 'provider_slug' => $e->provider_slug, 'endpoint' => '/v1/webhooks/'.rawurlencode($e->provider_slug), 'status' => match ($e->status->value) {
            'processed' => 'delivered','failed','dead_letter' => 'failed',default => 'retrying'
        }, 'processing_status' => $e->status->value, 'attempts' => $e->processing_attempts_count, 'last_attempt_at' => $e->processing_attempts_max_attempted_at, 'received_at' => $e->received_at->toIso8601String()], $page->items()), 'meta' => $this->meta($page)];
    }

    public function retry(string $id, string $actor, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($id, $actor, $correlation): array {
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['integration-webhook:'.$id]);
            $event = WebhookEventModel::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($event->status->value, ['failed', 'dead_letter'], true), 409, 'Only failed callbacks can be retried.');
            $event->status = 'received';
            $event->retry_count = 0;
            $event->last_error = null;
            $event->processing_generation++;
            $event->save();
            DB::connection('platform')->table('integration.admin_retry_intents')->insert(['id' => (string) Uuid::uuid7(), 'webhook_event_id' => $id, 'created_at' => now()]);
            $this->record('integration.webhook.retry_requested', $id, $actor, $correlation, ['generation' => $event->processing_generation]);

            return ['id' => $id, 'status' => 'retrying'];
        });
    }

    private function record(string $action, string $resource, string $actor, string $correlation, array $metadata): void
    {
        DB::connection('platform')->table('integration.admin_actions')->insert(['id' => (string) Uuid::uuid7(), 'actor_id' => $actor, 'action' => $action, 'resource_id' => $resource, 'correlation_id' => $correlation, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->audit->record(new AuditEventData(action: $action, actorType: 'admin', resourceType: 'integration', correlationId: $correlation, outcome: 'success', actorId: $actor, resourceId: $resource, metadata: $metadata));
    }

    private function meta($p): array
    {
        return ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()];
    }
}
