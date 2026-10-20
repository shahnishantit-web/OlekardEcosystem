<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\ApiController;
use App\Modules\Platform\Application\Services\ConfigurationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigurationKeyAdminController extends ApiController
{
    public function __construct(
        private readonly ConfigurationAdminService $adminService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_filter(
            $request->only(['domain', 'search', 'include_inactive']),
            fn ($v) => $v !== null && $v !== '',
        );

        // Cast include_inactive to bool from query string
        if (array_key_exists('include_inactive', $filters)) {
            $filters['include_inactive'] = filter_var($filters['include_inactive'], FILTER_VALIDATE_BOOLEAN);
        }

        $result = $this->adminService->listKeys(
            filters:       $filters,
            perPage:       (int) $request->input('per_page', 20),
            actorId:       $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        return $this->ok($result);
    }

    public function show(string $key, Request $request): JsonResponse
    {
        $result = $this->adminService->getKeyDetail(
            key:          $key,
            actorId:      $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        if ($result === null) {
            return $this->notFound("Configuration key '{$key}' not found.");
        }

        return $this->ok($result);
    }

    public function versions(string $key, Request $request): JsonResponse
    {
        $filters = array_filter([
            'from'       => $request->input('from'),
            'to'         => $request->input('to'),
            'maker_id'   => $request->input('maker_id'),
            'checker_id' => $request->input('checker_id'),
            'domain'     => $request->input('domain'),
        ], fn ($v) => $v !== null);

        $result = $this->adminService->getVersionHistory(
            key:           $key,
            perPage:       (int) $request->input('per_page', config('configuration.max_history_per_page', 50)),
            actorId:       $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
            filters:       $filters,
        );

        return $this->ok($result);
    }
}
