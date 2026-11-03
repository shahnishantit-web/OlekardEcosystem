<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\EventOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EventOrderController extends Controller
{
    public function __construct(private readonly EventOrderService $orders) {}

    public function quote(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->orders->createQuote(
            $eventId, $this->purchaser($request), $request->validate(['hold_ids' => ['required', 'array', 'min:1', 'max:50'], 'hold_ids.*' => ['required', 'uuid']])['hold_ids'],
            (string) $request->header('Idempotency-Key', ''),
        )], 201);
    }

    public function create(Request $request, string $eventId): JsonResponse
    {
        $input = $request->validate(['quote_id' => ['required', 'uuid'], 'customer_confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->orders->confirmAndCreateOrder(
            $eventId, $this->purchaser($request), $input['quote_id'], (string) $request->header('Idempotency-Key', ''),
        )], 201);
    }

    public function paymentIntent(Request $request, string $orderId): JsonResponse
    {
        return response()->json(['data' => $this->orders->createPaymentIntent(
            $orderId, $this->purchaser($request), (string) ($request->attributes->get('correlation_id') ?? Str::uuid()),
        )], 201);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        return response()->json(['data' => $this->orders->orderForPurchaser($orderId, $this->purchaser($request))])
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    private function purchaser(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor) && strtoupper((string) $request->attributes->get('identity.actor_type', '')) === 'CUSTOMER', 401,
            'Authenticated customer context is required.');

        return $actor;
    }
}
