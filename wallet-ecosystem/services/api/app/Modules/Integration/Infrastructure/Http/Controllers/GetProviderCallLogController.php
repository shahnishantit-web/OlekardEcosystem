<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Infrastructure\Http\Resources\ProviderCallLogResource;
use App\Modules\Integration\Infrastructure\Models\ProviderCallLogModel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetProviderCallLogController
{
    public function __invoke(Request $request, string $id): ProviderCallLogResource
    {
        $log = ProviderCallLogModel::find($id);

        if ($log === null) {
            throw new NotFoundHttpException('Provider call log not found.');
        }

        return new ProviderCallLogResource($log);
    }
}
