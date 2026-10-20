<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ReportExportModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportExportResource extends JsonResource
{
    public function __construct(
        ReportExportModel $resource,
        private readonly ?string $downloadUrl = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        /** @var ReportExportModel $export */
        $export = $this->resource;

        return [
            'id'                        => $export->id,
            'job_id'                    => $export->job_id,
            'format'                    => $export->format,
            'status'                    => $export->status,
            'row_count'                 => $export->row_count,
            'file_size_bytes'           => $export->file_size_bytes,
            'download_url'              => $export->isPurged() ? null : $this->downloadUrl,
            'token_expires_at'          => $export->token_expires_at?->toIso8601String(),
            'file_retention_expires_at' => $export->file_retention_expires_at?->toIso8601String(),
            'purged_at'                 => $export->purged_at?->toIso8601String(),
            'created_at'                => $export->created_at?->toIso8601String(),
        ];
    }
}
