<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentModuleConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * P20: Admin controller for module availability configuration.
 *
 * Changes are always submitted as drafts for maker-checker approval.
 * Permission enforcement via route middleware only — no $this->authorize().
 */
final class PaymentModuleConfigAdminController extends Controller
{
    public function __construct(
        private readonly PaymentModuleConfigService $moduleConfigService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $modules = $this->moduleConfigService->list();

        return response()->json([
            'data' => $modules->values()->all(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modules'                    => ['required', 'array', 'min:1'],
            'modules.*.module_key'       => ['required', 'string', 'max:100'],
            'modules.*.is_enabled'       => ['nullable', 'boolean'],
            'modules.*.display_order'    => ['nullable', 'integer'],
            'modules.*.description'      => ['nullable', 'string', 'max:2000'],
        ]);

        $actorId       = (string) ($request->user()?->id ?? 'system');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?? Uuid::uuid7()->toString());

        /** @var array<int, array<string, mixed>> $modules */
        $modules = $validated['modules'];

        $draft = $this->moduleConfigService->submitDraft(
            updates: $modules,
            actorId: $actorId,
            correlationId: $correlationId,
        );

        return response()->json([
            'requires_approval' => true,
            'draft_id'          => $draft->id,
            'message'           => 'Module config changes submitted for approval.',
        ], 202);
    }
}
