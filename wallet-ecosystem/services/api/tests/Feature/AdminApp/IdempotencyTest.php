<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    public function test_missing_idempotency_key_on_all_write_endpoints(): void
    {
        $writeEndpoints = [
            ['PATCH', '/v1/admin-app/users/some-id/status',               ['action' => 'suspend', 'reason' => 'test']],
            ['PATCH', '/v1/admin-app/roles/some-id/permissions',          ['permission_slugs' => []]],
            ['POST',  '/v1/admin-app/kyc/some-id/approve',                []],
            ['POST',  '/v1/admin-app/kyc/some-id/reject',                 ['reason' => 'test']],
            ['POST',  '/v1/admin-app/wallets/some-id/freeze',             ['reason' => 'test']],
            ['POST',  '/v1/admin-app/wallets/some-id/unfreeze',           ['reason' => 'test']],
            ['POST',  '/v1/admin-app/transactions/some-id/reverse',       ['reason' => 'test']],
            ['POST',  '/v1/admin-app/agents/some-id/approve',             []],
            ['PATCH', '/v1/admin-app/agents/some-id/status',              ['action' => 'suspend']],
            ['POST',  '/v1/admin-app/float/some-id/adjust',               ['direction' => 'credit', 'amount_minor_units' => 100]],
            ['POST',  '/v1/admin-app/reports',                            ['report_type' => 'test']],
        ];

        foreach ($writeEndpoints as [$method, $url, $body]) {
            $response = $this->actingAsAdminWithPermission('adminapp.users.write')
                ->json($method, $url, $body);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 422, 404],
                "Endpoint {$method} {$url} should return 403/422/404, got " . $response->getStatusCode(),
            );

            if ($response->getStatusCode() === 422) {
                $this->assertEquals(
                    'ADMINAPP_IDEM_001',
                    $response->json('error.code'),
                    "Expected ADMINAPP_IDEM_001 for {$method} {$url}",
                );
            }
        }
    }
}
