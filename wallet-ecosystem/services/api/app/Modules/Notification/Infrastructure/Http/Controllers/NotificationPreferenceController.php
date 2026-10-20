<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Controllers;

use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Domain\Exceptions\MandatoryNotificationCannotBeDisabledException;
use App\Modules\Notification\Domain\NotificationPermissions;
use App\Modules\Notification\Infrastructure\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Modules\Notification\Infrastructure\Http\Resources\NotificationPreferenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferenceService)
    {
        $this->middleware('identity.require-permission:'.NotificationPermissions::PREFERENCES_MANAGE);
    }

    public function show(Request $request): NotificationPreferenceResource
    {
        $preference = $this->preferenceService->getOrCreateForUser((string) $request->attributes->get('identity.user_id'));

        return new NotificationPreferenceResource($preference);
    }

    public function update(UpdateNotificationPreferenceRequest $request): NotificationPreferenceResource|JsonResponse
    {
        try {
            $preference = $this->preferenceService->updateForUser((string) $request->attributes->get('identity.user_id'), $request->validated());

            return new NotificationPreferenceResource($preference);
        } catch (MandatoryNotificationCannotBeDisabledException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'MANDATORY_CATEGORY_REQUIRED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
