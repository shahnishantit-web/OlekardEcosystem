<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Exceptions\TbdConfigurationException;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ConfigurationService implements ConfigurationServiceInterface
{
    private const CACHE_PREFIX = 'platform:config:';
    private const CACHE_TTL_SECONDS = 300;

    public function get(string $key, bool $requireValue = false): mixed
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        $record = Cache::store('redis')->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', $key)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('effective_from')
                      ->orWhere('effective_from', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('effective_until')
                      ->orWhere('effective_until', '>', now());
                })
                ->orderByDesc('version')
                ->first(['config_value', 'value_type', 'is_tbd']),
        );

        if ($record === null || $record->is_tbd) {
            if ($requireValue) {
                throw new TbdConfigurationException($key);
            }

            return null;
        }

        if ($record->value_type === 'json') {
            return json_decode($record->config_value, true);
        }

        return $record->config_value;
    }

    public function getOrDefault(string $key, mixed $default): mixed
    {
        $value = $this->get($key);
        return $value ?? $default;
    }

    public function isConfigured(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function invalidate(string $key): void
    {
        Cache::store('redis')->forget(self::CACHE_PREFIX.$key);
    }
}
