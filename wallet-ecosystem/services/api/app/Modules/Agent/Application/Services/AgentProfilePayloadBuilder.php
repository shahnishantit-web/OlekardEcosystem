<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use Illuminate\Support\Facades\DB;

/** Builds the Agent profile shape shared by login and session restoration. */
final class AgentProfilePayloadBuilder
{
    /** @return array<string, mixed>|null */
    public function build(string $userId, ?string $phone = null): ?array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->first(['mobile_number']);

        $agent = DB::connection('platform')
            ->table('agent.agents')
            ->where('user_id', $userId)
            ->first();

        if ($agent === null) {
            return null;
        }

        $profile = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->first();

        $application = DB::connection('platform')
            ->table('agent.agent_applications')
            ->where('id', $agent->application_id)
            ->first();

        $personalInfo = $application === null
            ? []
            : (json_decode((string) ($application->personal_info ?? '{}'), true) ?? []);
        $kycResult = $application->kyc_result ?? 'pending';

        return [
            'id' => $agent->id,
            'phone' => $phone ?? $profile?->mobile_number ?? $user?->mobile_number ?? '',
            'first_name' => $profile?->first_name ?? $personalInfo['first_name'] ?? 'Agent',
            'last_name' => $profile?->last_name ?? $personalInfo['last_name'] ?? 'User',
            'location_code' => $personalInfo['location_code'] ?? 'N/A',
            'status' => $agent->status,
            'kyc_status' => match ($kycResult) {
                'passed' => 'verified',
                'failed' => 'rejected',
                default => 'pending',
            },
            'created_at' => \Carbon\Carbon::parse($agent->created_at)->toIso8601String(),
        ];
    }
}
