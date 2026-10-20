<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Support\Application\Services\AdminCaseService;
use App\Modules\Support\Application\Services\AdminDisputeRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

final class AdminSupportController extends Controller
{
    public function __construct(private readonly AdminCaseService $cases, private readonly AdminDisputeRefundService $refunds, private readonly IdentityModuleContract $identity) {}

    private function actor(Request $r): string
    {
        return (string) $r->attributes->get('identity.user_id');
    }

    private function correlation(Request $r): string
    {
        $value = (string) $r->header('X-Correlation-ID');

        return Uuid::isValid($value) ? $value : (string) Uuid::uuid7();
    }

    private function result(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function summary(): JsonResponse
    {
        return $this->result($this->cases->summary());
    }

    public function index(Request $r): JsonResponse
    {
        $filters = $r->validate(['type' => ['sometimes', Rule::in(['Support', 'Dispute'])], 'status' => ['sometimes', Rule::in(['Open', 'In Progress', 'Escalated', 'Resolved'])], 'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])], 'date_from' => 'sometimes|date_format:Y-m-d', 'date_to' => ['sometimes', 'date_format:Y-m-d', Rule::when($r->filled('date_from'), ['after_or_equal:date_from'])], 'page' => 'sometimes|integer|min:1|max:100000']);

        return $this->result($this->cases->queue($filters));
    }

    public function show(Request $r, string $id): JsonResponse
    {
        return $this->result($this->cases->detail($id));
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $data = $r->validate(['action' => ['required', Rule::in(['note', 'link', 'assign', 'investigate', 'escalate'])], 'content' => 'required_if:action,note|string|max:10000', 'related_case_id' => 'required_if:action,link|uuid', 'assignee_id' => 'required_if:action,assign,escalate|uuid', 'reason' => ['required_if:action,escalate', Rule::in(['complex_investigation', 'financial_review', 'security_review', 'customer_appeal', 'other'])]]);
        $permission = match ($data['action']) {
            'assign' => 'support.assign','escalate' => 'dispute.escalate',default => 'support.investigate'
        };
        abort_unless($this->identity->hasPermission($this->actor($r), $permission), 403);

        return $this->result($this->cases->mutate($id, $data, $this->actor($r), $this->correlation($r)));
    }

    public function resolve(Request $r, string $id): JsonResponse
    {
        $data = $r->validate(['resolution_note' => 'required|string|min:1|max:10000']);
        $data['action'] = 'resolve';

        return $this->result($this->cases->mutate($id, $data, $this->actor($r), $this->correlation($r)));
    }

    public function refund(Request $r, string $id): JsonResponse
    {
        abort_unless($r->filled('step_up_token'), 403, 'Step-up verification required');
        $data = $r->validate(['amount_minor' => 'required|integer|min:1', 'reason' => 'required|string|min:1|max:10000', 'step_up_token' => 'required|string|max:8192', 'confirm' => 'sometimes|boolean']);
        $key = (string) $r->header('Idempotency-Key');
        abort_unless(Uuid::isValid($key), 422, 'Valid Idempotency-Key required');

        return $this->result($this->refunds->refund($id, $data, $this->actor($r), (string) $r->attributes->get('identity.session_id'), $key, $this->correlation($r)));
    }

    public function attach(Request $r, string $id): JsonResponse
    {
        $data = $r->validate(['filename' => 'required|string|max:255', 'content_base64' => 'required|string|max:13981016']);

        return $this->result($this->cases->attach($id, $data['filename'], $data['content_base64'], $this->actor($r), $this->correlation($r)));
    }

    public function download(Request $r, string $id, string $entryId): Response
    {
        $file = $this->cases->attachment($id, $entryId);

        return response($file['content'])->header('Content-Type', $file['mime'])->header('Content-Disposition', 'attachment; filename="evidence"')->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
