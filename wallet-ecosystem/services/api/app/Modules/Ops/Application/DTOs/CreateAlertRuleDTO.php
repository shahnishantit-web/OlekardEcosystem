<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\DTOs;

final readonly class CreateAlertRuleDTO
{
    public function __construct(
        public string  $name,
        public array   $metricCondition,
        public string  $severity,
        public array   $notificationChannels,
        public ?string $description    = null,
        public ?string $groupingKey    = null,
        public bool    $isEnabled      = true,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name'                  => $this->name,
            'metric_condition'      => $this->metricCondition,
            'severity'              => $this->severity,
            'notification_channels' => $this->notificationChannels,
            'description'           => $this->description,
            'grouping_key'          => $this->groupingKey,
            'is_enabled'            => $this->isEnabled,
        ], fn ($v) => $v !== null);
    }
}
