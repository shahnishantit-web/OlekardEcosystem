<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class AuditPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_token_on_list_endpoint_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);

        $this->actingAs($customer)
             ->getJson('/v1/audit/events')
             ->assertForbidden()
             ->assertJsonPath('error.code', 'AUTHORIZATION_001');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/audit/events')
             ->assertUnauthorized();
    }
}
