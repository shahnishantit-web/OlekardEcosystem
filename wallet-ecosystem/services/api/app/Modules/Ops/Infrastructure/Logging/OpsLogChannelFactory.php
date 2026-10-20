<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Logging;

use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use Monolog\Level;
use Monolog\Logger;

final class OpsLogChannelFactory
{
    public function __construct(
        private readonly LogRepositoryInterface $repository,
        private readonly LogSanitizer $sanitizer,
    ) {}

    public function __invoke(array $config): Logger
    {
        $levelName = strtoupper((string) ($config['level'] ?? 'info'));
        // @phpstan-ignore-next-line
        $level = Level::fromName($levelName);

        $logger = new Logger('ops_database');
        $logger->pushHandler(new DatabaseLogHandler(
            repository:  $this->repository,
            sanitizer:   $this->sanitizer,
            serviceName: 'api',
            level:       $level,
        ));

        return $logger;
    }
}
