<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationAuditModel;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationValueModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ConfigurationActivationJob implements ShouldQueue
{
    use Queueable;



    public function __construct(private readonly ConfigurationService $config)
    {
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
            DB::connection('platform')->transaction(function () use ($draft) {
                // Archive current active version
                ConfigurationValueModel::query()
                    ->where('config_key', $draft->config_key)
                    ->where('status', 'active')
                    ->update(['status' => 'archived']);

                // Activate new version
                $draft->update(['status' => 'active']);

                // Insert audit record (INSERT-only, not via Eloquent save())
                DB::connection('platform')->table('platform.configuration_audit')->insert([
                    'id'                      => (string) Uuid::uuid7(),
                    'configuration_value_id'  => $draft->id,
                    'action'                  => 'activated',
                    'actor_id'               => null,
                    'correlation_id'          => null,
                    'occurred_at'             => now(),
                ]);

                // Bust Redis cache for this key
                $this->config->invalidate($draft->config_key);
            });
        }
    }
}
