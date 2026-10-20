<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Application\Services\AdminNotificationService;
use App\Modules\Notification\Application\Services\NotificationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AdminNotificationController extends Controller
{
    public function __construct(private readonly AdminNotificationService $service) {}

    public function templates(): JsonResponse
    {
        return response()->json(['data' => $this->service->templates()]);
    }

    public function edit(Request $request): JsonResponse
    {
        $input = $request->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'subject_template' => 'required|string|max:500', 'body_template' => 'required|string|max:20000', 'language' => ['nullable', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*$/']]);

        return response()->json(['data' => $this->service->edit($input, $this->actor($request), $this->correlation($request))]);
    }

    public function preview(Request $request): JsonResponse
    {
        $input = $request->validate(['subject_template' => 'required|string|max:500', 'body_template' => 'required|string|max:20000', 'variables' => 'sometimes|array|max:50', 'variables.*' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->service->preview($input)]);
    }

    public function test(Request $request): JsonResponse
    {
        $input = $request->validate(['id' => 'required|uuid', 'request_id' => 'required|uuid', 'variables' => 'sometimes|array|max:50', 'variables.*' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->service->test($input, $this->actor($request), $this->correlation($request))], 202);
    }

    public function log(Request $request): JsonResponse
    {
        $input = $request->validate(['channel' => 'sometimes|in:email,sms,push,in_app', 'status' => 'sometimes|in:sent,failed,bounced,queued,delivered,permanently_failed', 'from' => 'sometimes|date_format:Y-m-d', 'to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json($this->service->log($input));
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->retry($id, $this->actor($request), $this->correlation($request))], 202);
    }

    public function rules(NotificationRuleService $rules): JsonResponse
    {
        return response()->json(['data' => $rules->listing()]);
    }

    public function updateRule(Request $request): JsonResponse
    {
        $input = $request->validate(['template_id' => 'required|uuid', 'channel' => 'required|in:email,sms,push,in_app', 'enabled' => 'required|boolean', 'revision' => 'required|integer|min:0']);

        return response()->json(['data' => $this->service->updateRule($input, $this->actor($request), $this->correlation($request))]);
    }

    private function actor(Request $request): string
    {
        $id = $request->attributes->get('identity.user_id');
        abort_unless(is_string($id) && Uuid::isValid($id), 401);

        return $id;
    }

    private function correlation(Request $request): string
    {
        $id = $request->header('X-Correlation-ID');

        return is_string($id) && Uuid::isValid($id) ? $id : (string) Uuid::uuid7();
    }
}
