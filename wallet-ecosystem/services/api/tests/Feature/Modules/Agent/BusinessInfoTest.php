<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T043 ————————————————————————————————————————————————

test('PATCH business-information on business-type application returns 200 and persists data', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id, AgentType::Business);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/business-information", [
            'trading_name'        => 'Acme Ltd',
            'registration_number' => 'REG-001',
            'tax_number'          => 'TAX-001',
        ]);

    $response->assertStatus(200);

    $row = DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('id', $application->id)
        ->first();

    expect($row)->not->toBeNull();
    $businessInfo = json_decode($row->business_info, true);
    expect($businessInfo['trading_name'])->toBe('Acme Ltd');
    expect($businessInfo['registration_number'])->toBe('REG-001');
    expect($businessInfo['tax_number'])->toBe('TAX-001');
});

test('PATCH business-information with empty body on individual-type application returns 200', function () {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id, AgentType::Individual);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/business-information", []);

    $response->assertStatus(200);
});

test('PATCH business-information with duplicate trading_name returns 422 DUPLICATE_BUSINESS_NAME', function () {
    $admin   = $this->makeActorWithPermissions('ADMIN', 'agent.approve');
    $owner1  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $owner2  = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');

    // Create an approved application with the trading name
    $this->createApprovedApplication($owner1->id, $admin->id);

    // Set business_info with trading name on the approved application
    DB::connection('platform')
        ->table('agent.agent_applications')
        ->where('user_id', $owner1->id)
        ->where('status', ApplicationStatus::Approved->value)
        ->update(['business_info' => json_encode(['trading_name' => 'TakenName Ltd'])]);

    // Now try to use the same trading name from a different application
    $app2 = $this->createAgentApplication($owner2->id, AgentType::Business);

    $response = $this->withActorToken($owner2)
        ->patchJson("/v1/agent-applications/{$app2->id}/business-information", [
            'trading_name' => 'TakenName Ltd',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'DUPLICATE_BUSINESS_NAME');
});
