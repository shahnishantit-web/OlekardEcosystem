<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiCorsTest extends TestCase
{
    public function test_preflights_cover_api_routes_without_resolving_dependencies(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        foreach (['/v1/auth/login', '/v1/customer/auth/login', '/v1/wallets', '/v1/admin/users',
            '/api/v1/transfers', '/api/v1/funding/transactions', '/api/v1/services', '/v1/unknown'] as $path) {
            $this->withHeaders([
                'Origin' => 'http://localhost:3000',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,authorization,x-correlation-id,idempotency-key',
            ])->options($path)->assertStatus(204)
                ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
                ->assertHeader('Access-Control-Allow-Headers', 'content-type,authorization,x-correlation-id,idempotency-key');
        }
    }

    public function test_untrusted_origins_are_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000']]);
        $this->withHeaders([
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/v1/auth/login')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_api_success_and_error_responses_have_cors_headers(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);
        Route::get('/v1/cors-test/success', fn () => response()->json(['ok' => true]));
        Route::get('/v1/cors-test/error', fn () => throw new \RuntimeException('CORS regression probe'));

        foreach (['success' => 200, 'error' => 500, 'missing' => 404] as $path => $status) {
            $this->withHeader('Origin', 'http://localhost:3000')
                ->getJson('/v1/cors-test/'.$path)->assertStatus($status)
                ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        }
    }
}
