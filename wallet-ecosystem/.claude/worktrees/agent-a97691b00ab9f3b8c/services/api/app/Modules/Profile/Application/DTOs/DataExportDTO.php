<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\DTOs;

final readonly class DataExportDTO
{
    public function __construct(
        public string                   $id,
        public string                   $userId,
        public string                   $status,
        public ?\DateTimeImmutable      $requestedAt,
        public ?\DateTimeImmutable      $completedAt,
        public ?\DateTimeImmutable      $expiresAt,
        public ?string                  $downloadKey,
    ) {}

    public static function fromModel(\App\Modules\Profile\Infrastructure\Models\DataExportModel $model): self
    {
        return new self(
            id:          $model->id,
            userId:      $model->user_id,
            status:      $model->status,
            requestedAt: $model->requested_at?->toDateTimeImmutable(),
            completedAt: $model->completed_at?->toDateTimeImmutable(),
            expiresAt:   $model->expires_at?->toDateTimeImmutable(),
            downloadKey: $model->download_key,
        );
    }
}
