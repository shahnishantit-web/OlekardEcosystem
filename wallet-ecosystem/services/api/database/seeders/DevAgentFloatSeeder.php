<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\AgentFloat\Application\Services\FloatLimitService;
use App\Modules\AgentFloat\Application\Services\FloatWalletProvisioningService;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local dashboard fixtures for the seeded Agent app account. */
final class DevAgentFloatSeeder extends Seeder
{
    private const AGENT_PHONE = '+26771000002';
    private const ADMIN_EMAIL = 'admin@olekard.local';
    private const CURRENCY_CODE = 'BWP';
    private const LOW_FLOAT_THRESHOLD_MINOR = 10_000;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevAgentFloatSeeder is limited to local/testing.');

            return;
        }

        $identity = DB::connection('identity');
        $platform = DB::connection('platform');
        $userId = $identity->table('identity.users')
            ->where('mobile_number', self::AGENT_PHONE)
            ->value('id');
        $adminId = $identity->table('identity.users')
            ->where('email', self::ADMIN_EMAIL)
            ->where('actor_type', 'ADMIN')
            ->value('id');

        if ($userId === null || $adminId === null) {
            throw new \RuntimeException(
                'Run IdentityAdminSeeder, CustomerAgentSeeder, and DevAgentSeeder first.'
            );
        }

        $agent = $platform->table('agent.agents')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first(['id']);

        if ($agent === null) {
            throw new \RuntimeException('The local demo Agent must be active before float setup.');
        }

        DB::connection('platform')->transaction(function () use ($agent, $adminId): void {
            if (! AgentFloatWalletModel::query()->where('agent_id', $agent->id)->exists()) {
                app(FloatWalletProvisioningService::class)->provision(
                    agentId: (string) $agent->id,
                    currencyCode: self::CURRENCY_CODE,
                    actorId: (string) $adminId,
                    idempotencyKey: 'dev-agent-float-wallet-'.$agent->id,
                    correlationId: (string) Str::uuid(),
                );
            }

            $limits = FloatLimitModel::query()->where('agent_id', $agent->id)->first();
            if ($limits === null || $limits->low_threshold_is_tbd || $limits->low_float_threshold === null) {
                app(FloatLimitService::class)->updateLimits(
                    agentId: (string) $agent->id,
                    limits: ['low_float_threshold' => self::LOW_FLOAT_THRESHOLD_MINOR],
                    actorId: (string) $adminId,
                    correlationId: (string) Str::uuid(),
                );
            }
        });

        $this->command?->info('Local demo Agent float wallet and dashboard threshold are ready.');
    }
}
