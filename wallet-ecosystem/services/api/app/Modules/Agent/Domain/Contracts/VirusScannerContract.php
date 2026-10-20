<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Contracts;

use App\Modules\Agent\Domain\ValueObjects\VirusScanResult;
use Illuminate\Http\UploadedFile;

interface VirusScannerContract
{
    public function scan(UploadedFile $file): VirusScanResult;
}
