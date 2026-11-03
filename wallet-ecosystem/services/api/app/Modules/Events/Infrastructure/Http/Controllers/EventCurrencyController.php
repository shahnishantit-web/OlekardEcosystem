<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventCurrencyAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventCurrencyController extends Controller
{
    public function __construct(private readonly EventCurrencyAssignmentService $currencies) {}

    public function assign(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', 'string', 'size:3'],
            'approval_reference' => ['required', 'string', 'max:160'],
            'policy_version' => ['required', 'string', 'max:80'],
        ]);
        $actorId = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actorId), 401, 'Authenticated actor context is missing.');
        $correlationId = (string) ($request->header('x-correlation-id') ?: Str::uuid());

        return response()->json(['data' => $this->currencies->assign(
            $eventId, $actorId, $data['currency_code'], $data['approval_reference'], $data['policy_version'], $correlationId,
        )]);
    }
}
