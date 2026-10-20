<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Controllers;

use App\Modules\Notification\Application\Services\InAppNotificationService;
use App\Modules\Notification\Domain\NotificationPermissions;
use App\Modules\Notification\Infrastructure\Http\Requests\MarkNotificationsReadRequest;
use App\Modules\Notification\Infrastructure\Http\Resources\NotificationDetailResource;
use App\Modules\Notification\Infrastructure\Http\Resources\NotificationSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class InAppNotificationController extends Controller
{
    public function __construct(private readonly InAppNotificationService $inAppService)
    {
        $this->middleware('identity.require-permission:'.NotificationPermissions::INBOX_READ);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $userId     = (string) $request->attributes->get('identity.user_id');
        $perPage    = min((int) $request->query('per_page', 20), 100);
        $page       = max((int) $request->query('page', 1), 1);
        $unreadOnly = filter_var($request->query('unread_only', false), FILTER_VALIDATE_BOOLEAN);
        $category   = $request->query('category');

        $paginator = $this->inAppService->listForUser($userId, $perPage, $page, $unreadOnly, $category);

        return NotificationSummaryResource::collection($paginator);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->inAppService->getUnreadCount((string) $request->attributes->get('identity.user_id'));

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    public function show(Request $request, string $id): NotificationDetailResource
    {
        $notification = $this->inAppService->findForUser((string) $request->attributes->get('identity.user_id'), $id);

        return new NotificationDetailResource($notification);
    }

    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $ids     = $request->input('ids');
        $markAll = (bool) $request->input('mark_all', false);

        $count = $this->inAppService->markRead((string) $request->attributes->get('identity.user_id'), $ids, $markAll);

        return response()->json(['data' => ['marked_count' => $count]]);
    }
}
