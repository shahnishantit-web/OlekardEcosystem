<?php

declare(strict_types=1);

use App\Exceptions\TbdConfigurationException;
use App\Modules\Platform\Application\Services\ConfigurationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

test('Redis cache hit returns value without DB query', function () {
    Cache::shouldReceive('store->remember')
        ->once()
        ->andReturn((object) ['config_value' => 'cached-value', 'is_tbd' => false, 'value_type' => 'string']);

    $service = new ConfigurationService();
    $result  = $service->get('test.key');

    expect($result)->toBe('cached-value');
});

test('TBD key with requireValue=false returns null', function () {
    Cache::shouldReceive('store->remember')
        ->andReturn((object) ['config_value' => null, 'is_tbd' => true]);

    $service = new ConfigurationService();
    $result  = $service->get('mfa.step_up_threshold', false);

    expect($result)->toBeNull();
});

test('TBD key with requireValue=true throws TbdConfigurationException', function () {
    Cache::shouldReceive('store->remember')
        ->andReturn((object) ['config_value' => null, 'is_tbd' => true]);

    $service = new ConfigurationService();

    expect(fn () => $service->get('mfa.step_up_threshold', true))
        ->toThrow(TbdConfigurationException::class);
});

test('invalidate deletes Redis key', function () {
    Cache::shouldReceive('store->forget')
        ->once()
        ->with('platform:config:test.key');

    $service = new ConfigurationService();
    $service->invalidate('test.key');
});

test('missing key returns null', function () {
    Cache::shouldReceive('store->remember')
        ->andReturn(null);

    $service = new ConfigurationService();
    $result  = $service->get('non.existent.key');

    expect($result)->toBeNull();
});
