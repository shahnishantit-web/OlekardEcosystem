<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Resources;

use App\Modules\Profile\Application\DTOs\DataExportDTO;
use Illuminate\Http\Resources\Json\JsonResource;

final class DataExportResource extends JsonResource
{
    public function __construct(
        private readonly DataExportDTO $export,
        private readonly ?string       $downloadUrl = null,
    ) {}

    public function toArray($request): array
    {
        return [
            'success' => true,
            'data'    => [
                'export_id'    => $this->export->id,
                'status'       => $this->export->status,
                'requested_at' => $this->export->requestedAt?->format(\DateTimeInterface::ATOM),
                'completed_at' => $this->export->completedAt?->format(\DateTimeInterface::ATOM),
                'expires_at'   => $this->export->expiresAt?->format(\DateTimeInterface::ATOM),
                'download_url' => $this->downloadUrl,
            ],
        ];
    }
}
