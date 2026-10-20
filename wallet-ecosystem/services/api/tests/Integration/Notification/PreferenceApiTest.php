<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Modules\Notification\Infrastructure\Models\NotificationPreferenceModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PreferenceApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
    }

    public function test_get_preferences_creates_defaults_on_first_access(): void
    {
        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.preferences.manage'])
            ->getJson('/api/v1/notifications/preferences');

        $response->assertOk();
        $response->assertJsonPath('data.marketing_opted_in', false);
        $response->assertJsonFragment(['push_enabled_categories' => ['financial', 'security', 'operational']]);
    }

    public function test_put_preferences_persists_and_get_returns_updated_values(): void
    {
        $payload = [
            'push_enabled_categories'  => ['financial', 'security', 'operational'],
            'sms_enabled_categories'   => ['financial', 'security'],
            'email_enabled_categories' => ['financial', 'security'],
            'marketing_opted_in'       => true,
        ];

        $putResponse = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.preferences.manage'])
            ->putJson('/api/v1/notifications/preferences', $payload);

        $putResponse->assertOk();
        $putResponse->assertJsonPath('data.marketing_opted_in', true);

        $getResponse = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.preferences.manage'])
            ->getJson('/api/v1/notifications/preferences');

        $getResponse->assertOk();
        $getResponse->assertJsonPath('data.marketing_opted_in', true);
    }

    public function test_put_preferences_with_missing_financial_returns_422(): void
    {
        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.preferences.manage'])
            ->putJson('/api/v1/notifications/preferences', [
                'push_enabled_categories' => ['operational'],
            ]);

        $response->assertStatus(422);
    }

    public function test_put_preferences_with_mandatory_category_required_error_code(): void
    {
        // Seed a preference first
        NotificationPreferenceModel::create([
            'id'                       => (string) Str::orderedUuid(),
            'user_id'                  => $this->userId,
            'push_enabled_categories'  => ['financial', 'security', 'operational'],
            'sms_enabled_categories'   => ['financial', 'security'],
            'email_enabled_categories' => ['security'],
            'marketing_opted_in'       => false,
        ]);

        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.preferences.manage'])
            ->putJson('/api/v1/notifications/preferences', [
                'sms_enabled_categories' => ['operational'],
            ]);

        $response->assertStatus(422);
    }
}
