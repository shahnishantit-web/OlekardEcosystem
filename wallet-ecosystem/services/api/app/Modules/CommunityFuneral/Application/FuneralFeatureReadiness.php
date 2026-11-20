<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application;

use App\Modules\Platform\Application\Services\ConfigurationService;

final class FuneralFeatureReadiness
{
    private const CONFIG_KEY = 'community_funeral.http_enabled';

    public function __construct(private readonly ConfigurationService $configuration) {}

    public function isEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        $configured = $this->configuration->get(self::CONFIG_KEY);
        if ($configured === null) {
            $configured = config('community_funeral.http_enabled', false);
        }

        if (is_array($configured)) {
            $configured = $configured['value'] ?? false;
        }

        if (is_string($configured)) {
            $configured = filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return $configured === true || $configured === 1;
    }
}
