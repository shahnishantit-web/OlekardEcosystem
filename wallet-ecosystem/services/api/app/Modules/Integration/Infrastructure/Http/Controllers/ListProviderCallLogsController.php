<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Infrastructure\Http\Resources\ProviderCallLogResource;
use App\Modules\Integration\Infrastructure\Models\ProviderCallLogModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListProviderCallLogsController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', '20'), 100);

        $query = ProviderCallLogModel::query()->orderBy('created_at', 'desc');

        if ($request->has('integration_id')) {
            $query->where('integration_id', $request->query('integration_id'));
        }

        if ($request->has('outcome')) {
            $query->where('outcome', $request->query('outcome'));
        }

        if ($request->has('correlation_id')) {
            $query->where('correlation_id', $request->query('correlation_id'));
        }

        return ProviderCallLogResource::collection($query->paginate($perPage));
    }
}
