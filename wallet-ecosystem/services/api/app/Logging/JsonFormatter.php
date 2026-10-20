<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

final class JsonFormatter extends NormalizerFormatter
{
    public function __construct()
    {
        parent::__construct(\DateTimeInterface::ATOM);
    }

    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        $output = [
            'timestamp'      => $normalized['datetime'],
            'level'          => strtolower($normalized['level_name']),
            'message'        => $normalized['message'],
            'correlation_id' => $normalized['extra']['correlation_id'] ?? null,
            'request_id'     => $normalized['extra']['request_id'] ?? null,
            'actor_id'       => $normalized['extra']['actor_id'] ?? null,
            'module'         => $normalized['extra']['module'] ?? null,
            'context'        => $normalized['context'] ?? [],
        ];

        return json_encode($output)."\n";
    }
}
