<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotEditableException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T038 ————————————————————————————————————————————————

test('updatePersonalInfo throws ApplicationNotEditableException for every non-editable status', function (ApplicationStatus $status) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id, status: $status);

    $payload = [
        'first_name'         => 'Jane',
        'last_name'          => 'Doe',
        'national_id_number' => '987654321',
        'national_id_type'   => 'passport',
        'date_of_birth'      => '1990-01-01',
        'phone'              => '+26771234568',
        'address'            => ['street' => 'A St', 'city' => 'Gabarone', 'country' => 'BW'],
    ];

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $payload);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'APPLICATION_NOT_EDITABLE');
})->with([
    'submitted'       => [ApplicationStatus::Submitted],
    'kyc_in_progress' => [ApplicationStatus::KycInProgress],
    'kyc_passed'      => [ApplicationStatus::KycPassed],
    'kyc_failed'      => [ApplicationStatus::KycFailed],
    'under_review'    => [ApplicationStatus::UnderReview],
    'approved'        => [ApplicationStatus::Approved],
    'rejected'        => [ApplicationStatus::Rejected],
]);

test('updatePersonalInfo does not throw for editable statuses', function (ApplicationStatus $status) {
    $user        = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $application = $this->createAgentApplication($user->id, status: $status);

    $payload = [
        'first_name'         => 'Jane',
        'last_name'          => 'Doe',
        'national_id_number' => '987654321',
        'national_id_type'   => 'passport',
        'date_of_birth'      => '1990-01-01',
        'phone'              => '+26771234568',
        'address'            => ['street' => 'A St', 'city' => 'Gaborone', 'country' => 'BW'],
    ];

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$application->id}/personal-information", $payload);

    $response->assertStatus(200);
})->with([
    'draft'       => [ApplicationStatus::Draft],
    'in_progress' => [ApplicationStatus::InProgress],
]);

test('updatePersonalInfo throws UnauthorizedCustomerAccessException when actor does not own application', function () {
    $owner    = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $intruder = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app      = $this->createAgentApplication($owner->id);

    $response = $this->withActorToken($intruder)
        ->patchJson("/v1/agent-applications/{$app->id}/personal-information", [
            'first_name'         => 'X',
            'last_name'          => 'Y',
            'national_id_number' => '000',
            'national_id_type'   => 'national_id',
            'date_of_birth'      => '1990-01-01',
            'phone'              => '+26771234568',
            'address'            => ['street' => 'A', 'city' => 'B', 'country' => 'BW'],
        ]);

    $response->assertStatus(403);
});

test('updatePersonalInfo on in_progress application does not transition status', function () {
    $user = $this->makeActorWithPermissions('CUSTOMER', 'agent.application.read');
    $app  = $this->createAgentApplication($user->id, status: ApplicationStatus::InProgress);

    $response = $this->withActorToken($user)
        ->patchJson("/v1/agent-applications/{$app->id}/personal-information", [
            'first_name'         => 'Jane',
            'last_name'          => 'Doe',
            'national_id_number' => '987654321',
            'national_id_type'   => 'passport',
            'date_of_birth'      => '1990-01-01',
            'phone'              => '+26771234568',
            'address'            => ['street' => 'A St', 'city' => 'Gaborone', 'country' => 'BW'],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', ApplicationStatus::InProgress->value);
});
