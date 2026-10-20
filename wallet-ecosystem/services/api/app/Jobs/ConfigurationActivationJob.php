<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationValueModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ConfigurationActivationJob implements ShouldQueue
{
    use Queueable;

    /** @param ConfigurationDomainValidatorInterface[] $validators */
    public function __construct(
        private readonly ConfigurationService $config,
        private readonly NotificationModuleContract $notifications,
        private readonly array $validators = [],
    ) {
        $this->onQueue('platform.critical');
    }

    public function handle(): void
    {
        $drafts = ConfigurationValueModel::query()
            ->where('status', 'draft')
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->get();

        foreach ($drafts as $draft) {
            $this->activateDraft($draft);
        }
    }

    private function activateDraft(ConfigurationValueModel $draft): void
    {
        // Protected security policies activate only through the audited checker service.
        // This also prevents stale scheduler snapshots from rolling back a newer policy.
        if (SecurityPolicyRegistry::contains($draft->config_key)) {
            return;
        }

        // Re-validate before activation (T028) — fail-safe against schema drift
        $domain = $this->resolveDomain($draft->config_key);
        $proposedValue = json_decode($draft->config_value, true) ?? $draft->config_value;

        foreach ($this->validators as $validator) {
            if ($validator->supportsDomain($domain)) {
                try {
                    $validator->validate($draft->config_key, $proposedValue);
                } catch (ValidationException $e) {
                    $this->handleActivationFailure($draft, $e->getMessage());

                    return;
                }
                break;
            }
        }

        DB::connection('platform')->transaction(function () use ($draft) {
            // Archive current active version
            ConfigurationValueModel::query()
                ->where('config_key', $draft->config_key)
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            // Activate new version
            $draft->update(['status' => 'active']);

            // Insert legacy audit record (INSERT-only, not via Eloquent save())
            DB::connection('platform')->table('platform.configuration_audit')->insert([
                'id' => (string) Uuid::uuid7(),
                'configuration_value_id' => $draft->id,
                'action' => 'activated',
                'actor_id' => null,
                'correlation_id' => null,
                'occurred_at' => now(),
            ]);

            // T027: Write Activated version record to immutable configuration_versions (FR-016)
            if ($draft->change_request_id !== null) {
                $changeRequest = DB::connection('platform')
                    ->table('platform.configuration_change_requests')
                    ->where('id', $draft->change_request_id)
                    ->first(['maker_id', 'previous_value']);

                DB::connection('platform')->table('platform.configuration_versions')->insert([
                    'id' => (string) Uuid::uuid7(),
                    'config_key' => $draft->config_key,
                    'domain' => $this->resolveDomain($draft->config_key)->value,
                    'event_type' => VersionEventType::Activated->value,
                    'value' => $draft->config_value,
                    'previous_value' => $changeRequest?->previous_value,
                    'maker_id' => $changeRequest?->maker_id,
                    'checker_id' => null,
                    'actor_id' => null,
                    'change_request_id' => $draft->change_request_id,
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
            }

            // Bust Redis cache for this key
            $this->config->invalidate($draft->config_key);
        });
    }

    private function handleActivationFailure(ConfigurationValueModel $draft, string $reason): void
    {
        DB::connection('platform')->transaction(function () use ($draft, $reason) {
            $draft->update(['status' => 'failed']);

            if ($draft->change_request_id !== null) {
                // Transition change request to failed
                DB::connection('platform')
                    ->table('platform.configuration_change_requests')
                    ->where('id', $draft->change_request_id)
                    ->where('status', ChangeRequestStatus::Approved->value)
                    ->update([
                        'status' => ChangeRequestStatus::Failed->value,
                        'rejection_reason' => $reason,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);

                // Write Failed version record (FR-015: include maker_id from change_request)
                $changeReq = DB::connection('platform')
                    ->table('platform.configuration_change_requests')
                    ->where('id', $draft->change_request_id)
                    ->first(['maker_id', 'previous_value']);

                DB::connection('platform')->table('platform.configuration_versions')->insert([
                    'id' => (string) Uuid::uuid7(),
                    'config_key' => $draft->config_key,
                    'domain' => $this->resolveDomain($draft->config_key)->value,
                    'event_type' => VersionEventType::Failed->value,
                    'value' => null,
                    'previous_value' => $changeReq?->previous_value,
                    'maker_id' => $changeReq?->maker_id,
                    'checker_id' => null,
                    'actor_id' => null,
                    'change_request_id' => $draft->change_request_id,
                    'notes' => $reason,
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);

                // Notify maker of failure via E17 NotificationService (T028)
                $changeRequest = DB::connection('platform')
                    ->table('platform.configuration_change_requests')
                    ->where('id', $draft->change_request_id)
                    ->first();

                if ($changeRequest !== null) {
                    try {
                        $this->notifications->dispatch((string) $changeRequest->maker_id, 'in_app', [
                            'type' => 'configuration.change_request.failed',
                            'request_id' => $changeRequest->id,
                            'config_key' => $draft->config_key,
                            'reason' => $reason,
                        ]);
                    } catch (\Throwable) {
                        // Notification failure must not affect activation failure handling
                    }
                }
            }
        });
    }

    private function resolveDomain(string $configKey): ConfigurationDomain
    {
        if (str_starts_with($configKey, 'financial_policy.')) {
            return ConfigurationDomain::FinancialPolicy;
        }

        if (str_starts_with($configKey, 'geographic.')) {
            return ConfigurationDomain::Geographic;
        }

        return ConfigurationDomain::OperationalPolicy;
    }
}
