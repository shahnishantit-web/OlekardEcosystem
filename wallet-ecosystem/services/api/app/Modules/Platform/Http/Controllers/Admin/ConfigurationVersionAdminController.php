<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Modules\Platform\Application\Services\ConfigurationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigurationVersionAdminController extends ApiController
{
    public function __construct(
        private readonly ConfigurationAdminService $adminService,
    ) {}

    public function index(string $key, Request $request): JsonResponse
    {
        $perPage = (int) $request->input(
            'per_page',
            config('configuration.max_history_per_page', 50)
        );

        $filters = array_filter([
            'from'       => $request->input('from'),
            'to'         => $request->input('to'),
            'maker_id'   => $request->input('maker_id'),
            'checker_id' => $request->input('checker_id'),
            'domain'     => $request->input('domain'),
        ], fn ($v) => $v !== null);

        $result = $this->adminService->getVersionHistory(
            key:           $key,
            perPage:       $perPage,
            actorId:       $request->user()?->id,
            correlationId: $request->header('X-Correlation-ID', ''),
            filters:       $filters,
        );

        return $this->ok($result);
    }
}
