<?php

declare(strict_types=1);

use App\Exceptions\TbdConfigurationException;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Tests\Support\PlatformTestCase;

uses(PlatformTestCase::class);

// Known TBD keys that MUST return null and never a fabricated default
$tbdKeys = [
    'mfa.step_up_threshold',
    'provider.payment_processor',
    'reconciliation.tolerance',
    'events.outbox.processing_timeout_seconds',
];

foreach ($tbdKeys as $key) {
    test("TBD key '{$key}' returns null without requireValue", function () use ($key) {
        $service = app(ConfigurationService::class);

        $result = $service->get($key, false);

        expect($result)->toBeNull("TBD key '{$key}' must return null, not a fabricated default.");
    });

    test("TBD key '{$key}' throws TbdConfigurationException with requireValue=true", function () use ($key) {
        $service = app(ConfigurationService::class);

        expect(fn () => $service->get($key, true))
            ->toThrow(TbdConfigurationException::class);
    });

    test("TBD key '{$key}' isConfigured() returns false", function () use ($key) {
        $service = app(ConfigurationService::class);

        expect($service->isConfigured($key))->toBeFalse();
    });
}
