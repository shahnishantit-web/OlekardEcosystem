<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Platform\Application\Services\ConfigurationApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigurationController extends ApiController
{
    public function __construct(
        private readonly ConfigurationApplicationService $configService,
    ) {}

    public function show(string $key): JsonResponse
    {
        $value = $this->configService->getValue($key);

        if ($value === null) {
            return $this->notFound("Configuration key '{$key}' not found or not active.");
        }

        return $this->ok(['key' => $key, 'value' => $value]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'namespace'   => ['required', 'string', 'max:64'],
            'key'         => ['required', 'string', 'max:255'],
            'value'       => ['required'],
            'value_type'  => ['required', 'in:string,integer,boolean,json'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = $this->configService->createValue(
            namespace:   $validated['namespace'],
            key:         $validated['key'],
            value:       $validated['value'],
            valueType:   $validated['value_type'],
            description: $validated['description'] ?? null,
            actorId:     $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        return $this->created($record);
    }

    public function activate(string $id, Request $request): JsonResponse
    {
        $this->configService->activateValue(
            id:           $id,
            actorId:      $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-ID', ''),
        );

        return $this->ok(['id' => $id, 'status' => 'active']);
    }
}
