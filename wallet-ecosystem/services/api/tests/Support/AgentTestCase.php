<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Agent\Infrastructure\Models\AgentApplicationModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

abstract class AgentTestCase extends KycTestCase
{
    protected $connectionsToTransact = [null, 'identity'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('agent-documents');
    }

    // ── Agent application factory helpers ─────────────────────────────────

    public function createAgentApplication(
        string $userId,
        AgentType $agentType = AgentType::Individual,
        ApplicationStatus $status = ApplicationStatus::Draft,
    ): AgentApplicationModel {
        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id'             => $id,
            'user_id'        => $userId,
            'agent_type'     => $agentType->value,
            'status'         => $status->value,
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return AgentApplicationModel::on('platform')->find($id);
    }

    protected function createApprovedApplication(
        string $userId,
        string $adminId,
    ): array {
        $appId = (string) Uuid::uuid7();

        DB::connection('platform')->table('agent.agent_applications')->insert([
            'id'             => $appId,
            'user_id'        => $userId,
            'agent_type'     => AgentType::Individual->value,
            'status'         => ApplicationStatus::Approved->value,
            'kyc_result'     => 'passed',
            'approved_at'    => now(),
            'correlation_id' => (string) Uuid::uuid7(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $agentId = (string) Uuid::uuid7();

        DB::connection('platform')->table('agent.agents')->insert([
            'id'             => $agentId,
            'application_id' => $appId,
            'user_id'        => $userId,
            'agent_type'     => AgentType::Individual->value,
            'status'         => AgentStatus::Active->value,
            'approved_by'    => $adminId,
            'approved_at'    => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return [
            'application' => AgentApplicationModel::on('platform')->find($appId),
            'agent'       => AgentModel::on('platform')->find($agentId),
        ];
    }

    protected function makeAgentUser(string ...$permissions): object
    {
        return $this->makeActorWithPermissions('AGENT', ...$permissions);
    }

    protected function createLinkedCustomer(
        string $agentId,
        ?string $phone = null,
        string $firstName = 'Jane',
        string $lastName = 'Doe',
        string $status = 'pending_kyc',
    ): object {
        $customerId    = (string) Uuid::uuid7();
        $mobileNumber  = $phone ?? '+26771' . mt_rand(100000, 999999);
        $nationalId    = 'BW' . mt_rand(100000000, 999999999);

        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $customerId,
            'mobile_number' => $mobileNumber,
            'status'        => $status,
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::connection('platform')->table('agent.agent_customer_relationships')->insert([
            'id'                 => (string) Uuid::uuid7(),
            'agent_id'           => $agentId,
            'customer_id'        => $customerId,
            'registered_by'      => $agentId,
            'registered_at'      => now(),
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'mobile_number'      => $mobileNumber,
            'national_id_type'   => 'national_id',
            'national_id_number' => $nationalId,
            'customer_status'    => $status,
        ]);

        return (object) [
            'id'                 => $customerId,
            'first_name'         => $firstName,
            'last_name'          => $lastName,
            'phone'              => $mobileNumber,
            'national_id_number' => $nationalId,
            'status'             => $status,
        ];
    }
}
