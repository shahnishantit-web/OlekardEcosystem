<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\DTOs;

final readonly class UpdateAlertRuleDTO
{
    public function __construct(
        public ?string $name                  = null,
        public ?array  $metricCondition       = null,
        public ?string $severity              = null,
        public ?array  $notificationChannels  = null,
        public ?string $description           = null,
        public ?string $groupingKey           = null,
        public ?bool   $isEnabled             = null,
    ) {}

    public function toArray(): array
    {
        $data = [];

        if ($this->name !== null)                 { $data['name']                  = $this->name; }
        if ($this->metricCondition !== null)      { $data['metric_condition']      = $this->metricCondition; }
        if ($this->severity !== null)             { $data['severity']              = $this->severity; }
        if ($this->notificationChannels !== null) { $data['notification_channels'] = $this->notificationChannels; }
        if ($this->description !== null)          { $data['description']           = $this->description; }
        if ($this->groupingKey !== null)          { $data['grouping_key']          = $this->groupingKey; }
        if ($this->isEnabled !== null)            { $data['is_enabled']            = $this->isEnabled; }

        return $data;
    }
}
