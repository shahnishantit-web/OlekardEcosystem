<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Logging;

use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class DatabaseLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogRepositoryInterface $repository,
        private readonly LogSanitizer $sanitizer,
        private readonly string $serviceName = 'api',
        int|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        // @phpstan-ignore-next-line
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $correlationId = $record->extra['correlation_id']
                ?? $record->context['correlation_id']
                ?? '00000000-0000-0000-0000-000000000000';

            $severity = $this->mapLevel($record->level);
            $context  = $this->sanitizer->sanitize($record->context);

            $this->repository->create(
                $this->serviceName,
                (string) $correlationId,
                $severity,
                $record->message,
                $context,
            );
        } catch (Throwable) {
            // Never let logging failures crash the application.
        }
    }

    private function mapLevel(Level $level): LogSeverity
    {
        return match (true) {
            $level->value >= Level::Critical->value => LogSeverity::Critical,
            $level->value >= Level::Error->value    => LogSeverity::Error,
            $level->value >= Level::Warning->value  => LogSeverity::Warning,
            $level->value >= Level::Info->value     => LogSeverity::Info,
            default                                  => LogSeverity::Debug,
        };
    }
}
