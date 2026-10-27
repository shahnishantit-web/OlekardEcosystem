<?php

declare(strict_types=1);

namespace Database\Seeders\Payment;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T011: Seeds the integration_profiles catalogue with reviewed adapter implementations.
 * Idempotent — upserts on (profile_identifier, version).
 */
final class IntegrationProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            [
                'profile_identifier'        => 'mock-bank-v1',
                'version'                   => '1.0.0',
                'display_name'              => 'Mock Bank (Development Only)',
                'description'               => 'Development-only mock adapter for testing payment flows without a real bank connection. Production-blocked.',
                'supported_auth_mechanisms' => json_encode(['api_key']),
                'supported_capabilities'    => json_encode(['initiate', 'query', 'cancel', 'callback']),
                'config_schema'             => json_encode(['type' => 'object', 'properties' => []]),
                'is_active'                 => true,
                'notes'                     => 'Development environment only. Never activate in production.',
            ],
            [
                'profile_identifier'        => 'http-redirect-v1',
                'version'                   => '1.0.0',
                'display_name'              => 'HTTP Redirect (Standard)',
                'description'               => 'Standard HTTP redirect-based bank payment adapter. Supports bearer token and API key auth. Suitable for most bank redirect integrations.',
                'supported_auth_mechanisms' => json_encode(['bearer_token', 'api_key', 'hmac_signed']),
                'supported_capabilities'    => json_encode(['initiate', 'query', 'cancel', 'full_refund', 'partial_refund', 'callback', 'reconciliation']),
                'config_schema'             => json_encode([
                    'type'       => 'object',
                    'properties' => [
                        'base_url'   => ['type' => 'string', 'format' => 'uri'],
                        'auth_profile' => ['type' => 'string', 'enum' => ['bearer_token', 'api_key', 'hmac_signed']],
                    ],
                ]),
                'is_active'                 => true,
                'notes'                     => null,
            ],
        ];

        foreach ($profiles as $profile) {
            $now = now()->toIso8601String();
            DB::connection('payment')->table('payment.integration_profiles')->upsert(
                array_merge($profile, [
                    'id'         => (string) Str::uuid7(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
                ['profile_identifier', 'version'],
                ['display_name', 'description', 'supported_auth_mechanisms', 'supported_capabilities', 'config_schema', 'is_active', 'notes', 'updated_at']
            );
        }
    }
}
