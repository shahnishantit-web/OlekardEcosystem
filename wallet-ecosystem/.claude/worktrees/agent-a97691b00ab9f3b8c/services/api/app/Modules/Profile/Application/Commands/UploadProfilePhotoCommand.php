<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

use Illuminate\Http\UploadedFile;

final readonly class UploadProfilePhotoCommand
{
    public function __construct(
        public string       $userId,
        public string       $actorId,
        public string       $actorType,
        public string       $correlationId,
        public UploadedFile $file,
    ) {}
}
