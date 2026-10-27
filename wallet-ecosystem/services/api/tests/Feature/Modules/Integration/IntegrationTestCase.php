<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Integration;

use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * Base test class for Integration module feature tests.
 *
 * Extends LedgerTestCase (DatabaseTransactions on null + identity, FakeIdentityAuthMiddleware).
 */
abstract class IntegrationTestCase extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        // Isolated source per test; real Redis sliding-window keys expire in 61s.
        // Never flush the shared application Redis database.
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254)]);
    }

    protected function postSignedWebhook(string $url, array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'integration-regression-test-only')], $raw);
    }

    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity', 'platform'];

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function ensureApiClient(array $overrides = []): array
    {
        $apiKey = bin2hex(random_bytes(16)); // 32-char hex
        $rawSecret = bin2hex(random_bytes(32)); // 64-char hex
        $id = (string) Uuid::uuid7();

        $data = array_merge([
            'id' => $id,
            'name' => 'Test Client',
            'api_key' => $apiKey,
            'secret_hash' => password_hash($rawSecret, PASSWORD_BCRYPT),
            'status' => ApiClientStatus::Active->value,
            'permissions' => json_encode(['integrations.read']),
            'rate_limit_per_minute' => 60,
            'last_used_at' => null,
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::connection('platform')->table('integration.api_clients')->insert($data);

        return [
            'id' => $data['id'],
            'api_key' => $apiKey,
            'raw_secret' => $rawSecret,
            'bearer' => base64_encode("{$apiKey}:{$rawSecret}"),
        ];
    }

    protected function ensureIntegration(array $overrides = []): string
    {
        $id = (string) Uuid::uuid7();

        $data = array_merge([
            'id' => $id,
            'provider_type' => IntegrationProviderType::Sms->value,
            'provider_slug' => 'mock-sms',
            'display_name' => 'Mock SMS',
            'credentials_reference' => 'platform.integrations.mock_sms',
            'endpoint_override' => null,
            'status' => IntegrationStatus::Active->value,
            'is_default' => true,
            'configuration' => null,
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        DB::connection('platform')->table('integration.integrations')->insert($data);

        $this->app->instance('integration.webhook.'.$data['provider_slug'], new class implements ProviderWebhookContract
        {
            public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent
            {
                if (! hash_equals(hash_hmac('sha256', $rawPayload, 'integration-regression-test-only'), $signature)) {
                    throw new WebhookSignatureException('Invalid test signature');
                }
                $payload = json_decode($rawPayload, true);

                return new NormalizedWebhookEvent(hash('sha256', $rawPayload), (string) Uuid::uuid7(), null, null, 'test-provider', null, $payload['event'] ?? 'generic', ProviderStatus::SUCCESS, new \DateTimeImmutable, $rawPayload);
            }
        });

        return $id;
    }

    protected function bearerHeader(string $apiKey, string $rawSecret): string
    {
        return 'Bearer '.base64_encode("{$apiKey}:{$rawSecret}");
    }
}
