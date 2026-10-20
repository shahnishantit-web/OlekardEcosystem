<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Queries;

use App\Modules\Profile\Application\DTOs\DataExportDTO;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;

final class GetDataExportStatusQuery
{
    public function __construct(
        private readonly EloquentDataExportRepository $exports,
    ) {}

    public function execute(string $exportId): ?DataExportDTO
    {
        $model = $this->exports->findById($exportId);
        return $model !== null ? DataExportDTO::fromModel($model) : null;
    }
}
