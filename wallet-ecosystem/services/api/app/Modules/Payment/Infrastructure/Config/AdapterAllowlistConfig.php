<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Config;

use App\Modules\Payment\Domain\Contracts\AdapterAllowlistInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Support\Facades\Log;

final class AdapterAllowlistConfig implements AdapterAllowlistInterface
{
    private const FALLBACK = ['olekard_wallet', 'mock_bank'];
    private const CONFIG_KEY = 'payment.adapter_allowlist';

    /** @var string[]|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {
    }

    public function isAllowed(string $adapterIdentifier): bool
    {
        return in_array($adapterIdentifier, $this->getAllowedIdentifiers(), true);
    }

    /** @return string[] */
    public function getAllowedIdentifiers(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $raw = $this->config->getOrDefault(self::CONFIG_KEY, null);

        if ($raw === null) {
            Log::warning('payment.adapter_allowlist config key not set; using hardcoded fallback list');
            $this->resolved = self::FALLBACK;
            return $this->resolved;
        }

        if (is_string($raw)) {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : self::FALLBACK;
        }

        if (! is_array($raw) || empty($raw)) {
            // Explicit empty array means no adapters permitted (fail closed)
            $this->resolved = is_array($raw) ? [] : self::FALLBACK;
            return $this->resolved;
        }

        $this->resolved = array_values(array_filter($raw, 'is_string'));
        return $this->resolved;
    }
}
