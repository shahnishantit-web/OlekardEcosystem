<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Application\Services\PendingAdminActionService;
use App\Modules\AdminApp\Infrastructure\Http\Resources\AdminAgentResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Agent\Application\Services\AgentLifecycleService;
use App\Modules\AgentFloat\Application\Services\FloatFundingService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentController
{
    public function __construct(
        private readonly AgentLifecycleService $agentLifecycleService,
        private readonly FloatFundingService $floatFundingService,
        private readonly PendingAdminActionService $pendingActions,
        private readonly AuditServiceInterface $audit,
        private readonly LedgerModuleContract $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('platform')->table('agent.agents');

        if ($q = $request->query('q', $request->query('search'))) {
            $applicationIds = DB::connection('platform')->table('agent.agent_applications')
                ->where(function ($sub) use ($q): void {
                    $sub->whereRaw('business_info::text ilike ?', ["%{$q}%"])
                        ->orWhereRaw('personal_info::text ilike ?', ["%{$q}%"]);
                })->pluck('id');
            $query->where(function ($sub) use ($q, $applicationIds): void {
                $sub->whereIn('application_id', $applicationIds)
                    ->orWhereIn('user_id', DB::connection('profile')->table('profile.user_profiles')
                        ->where(function ($profile) use ($q): void {
                            $profile->where('first_name', 'ilike', "%{$q}%")
                                ->orWhere('last_name', 'ilike', "%{$q}%")
                                ->orWhere('mobile_number', 'ilike', "%{$q}%");
                        })->pluck('user_id'));
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
        $data = $this->formatAgents($paginated->getCollection());

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $agentId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $agent = DB::connection('platform')->table('agent.agents')->where('id', $agentId)->first();

        if ($agent === null) {
            return ErrorResource::make('ADMINAPP_AGENT_001', 'Agent not found.', $correlationId, 404);
        }

        return response()->json(['data' => $this->formatAgents(collect([$agent]))->first()]);
    }

    /** @param \Illuminate\Support\Collection<int, object> $agents */
    private function formatAgents($agents)
    {
        $userIds = $agents->pluck('user_id');
        $applicationIds = $agents->pluck('application_id');
        $profiles = DB::connection('profile')->table('profile.user_profiles')->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $users = DB::connection('identity')->table('identity.users')->whereIn('id', $userIds)->get()->keyBy('id');
        $applications = DB::connection('platform')->table('agent.agent_applications')->whereIn('id', $applicationIds)->get()->keyBy('id');
        $floats = DB::connection('platform')->table('agent.float_wallets')->whereIn('agent_id', $agents->pluck('id'))->get()->keyBy('agent_id');
        $limits = DB::connection('platform')->table('agent.float_limits')->whereIn('agent_id', $agents->pluck('id'))->get()->keyBy('agent_id');

        return $agents->map(function ($agent) use ($profiles, $users, $applications, $floats, $limits): array {
            $profile = $profiles->get($agent->user_id);
            $identity = $users->get($agent->user_id);
            $application = $applications->get($agent->application_id);
            $business = $this->jsonObject($application->business_info ?? null);
            $personal = $this->jsonObject($application->personal_info ?? null);
            $float = $floats->get($agent->id);
            $limit = $limits->get($agent->id);
            $agent->agent_id = $agent->id;
            $agent->agent_status = $agent->status;
            $agent->full_name = trim(($profile->first_name ?? $personal['first_name'] ?? '').' '.($profile->last_name ?? $personal['last_name'] ?? ''));
            $agent->phone_number = $profile->mobile_number ?? $identity->mobile_number ?? null;
            $agent->business_name = $business['business_name'] ?? $business['name'] ?? null;
            $agent->location = $business['location'] ?? null;
            $agent->approved_at = $agent->approved_at ?? $application->approved_at ?? null;
            $agent->float_limit_minor_units = (int) ($limit->max_balance ?? 0);
            $agent->float_balance_minor_units = $float ? $this->ledger->getBalance($float->ledger_account_reference) : 0;
            $agent->float_currency = 'BWP';
            return AdminAgentResource::format($agent);
        });
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function approve(Request $request, string $agentId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');

        $agent = DB::connection('platform')->table('agent.agents')->where('id', $agentId)->first(['id', 'status']);

        if ($agent === null) {
            $application = DB::connection('platform')->table('agent.agent_applications')->where('id', $agentId)->first(['id', 'status']);
            if ($application === null) {
                return ErrorResource::make('ADMINAPP_AGENT_001', 'Agent not found.', $correlationId, 404);
            }
            if (! in_array($application->status, ['under_review', 'kyc_passed'], true)) {
                return ErrorResource::make('ADMINAPP_AGENT_002', 'Agent application is not awaiting review.', $correlationId, 422);
            }
        } else {
            if ($agent->status !== 'pending_approval') {
                return ErrorResource::make('ADMINAPP_AGENT_002', 'Agent is not in pending_approval state.', $correlationId, 422);
            }
        }

        $pendingAction = $this->pendingActions->submit(
            actionType:     'adminapp.agent.approve',
            resourceType:   'Agent',
            resourceId:     $agentId,
            payload:        [],
            makerId:        $actorId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $this->audit->record(new AuditEventData(
            action:        'adminapp.agent.approve.requested',
            actorType:     'USER',
            resourceType:  'Agent',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $agentId,
            afterState:    ['approval_id' => $pendingAction->id],
        ));

        return response()->json([
            'data' => [
                'approval_id' => $pendingAction->id,
                'action_type' => 'adminapp.agent.approve',
                'status'      => 'pending_approval',
                'expires_at'  => $pendingAction->expires_at->toIso8601ZuluString(),
            ],
        ], 202);
    }

    public function updateStatus(Request $request, string $agentId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $action         = $request->input('action');
        $reason         = $request->input('reason', '');

        $agent = DB::connection('platform')->table('agent.agents')->where('id', $agentId)->first(['id', 'status']);

        if ($agent === null) {
            return ErrorResource::make('ADMINAPP_AGENT_001', 'Agent not found.', $correlationId, 404);
        }

        if ($action === 'suspend') {
            $updatedAgent = $this->agentLifecycleService->suspend($agentId, $actorId, $reason, $idempotencyKey, $correlationId);
            $newStatus = $updatedAgent->status;
        } elseif ($action === 'reactivate') {
            $updatedAgent = $this->agentLifecycleService->reactivate($agentId, $actorId, $idempotencyKey, $correlationId);
            $newStatus = $updatedAgent->status;
        } else {
            return ErrorResource::make('ADMINAPP_AGENT_003', 'Invalid action. Use suspend or reactivate.', $correlationId, 422);
        }

        $this->audit->record(new AuditEventData(
            action:        "adminapp.agent.{$action}",
            actorType:     'USER',
            resourceType:  'Agent',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $agentId,
            afterState:    ['status' => $newStatus, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'agent_id'     => $agentId,
                'agent_status' => $newStatus,
                'updated_at'   => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    public function floatIndex(Request $request): JsonResponse
    {
        $query = DB::connection('platform')->table('agent.agents')->where('status', 'active');

        if ($q = $request->query('q', $request->query('search'))) {
            $profileIds = DB::connection('profile')->table('profile.user_profiles')->where(function ($sub) use ($q): void {
                $sub->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%")
                    ->orWhere('mobile_number', 'ilike', "%{$q}%");
            })->pluck('user_id');
            $query->where(function ($sub) use ($q, $profileIds): void {
                $sub->whereIn('user_id', $profileIds)
                    ->orWhereIn('application_id', DB::connection('platform')->table('agent.agent_applications')
                        ->whereRaw('business_info::text ilike ?', ["%{$q}%"])->pluck('id'));
            });
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
        $data = $this->formatAgents($paginated->getCollection())->map(function (array $agent): array {
            $agent['utilization_pct'] = $agent['float_limit_minor_units'] > 0
                ? round($agent['float_balance_minor_units'] / $agent['float_limit_minor_units'] * 100, 2) : 0;
            $agent['last_top_up_at'] = null;
            return $agent;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function floatAdjust(Request $request, string $agentId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $direction      = $request->input('direction');
        $amount         = (int) $request->input('amount_minor_units', 0);
        $reason         = $request->input('reason', '');

        $agent = DB::connection('platform')->table('agent.agents')->where('id', $agentId)->first(['id']);

        if ($agent === null) {
            return ErrorResource::make('ADMINAPP_AGENT_001', 'Agent not found.', $correlationId, 404);
        }

        $float = DB::connection('platform')->table('agent.float_wallets')->where('agent_id', $agentId)->first(['ledger_account_reference']);
        $limits = DB::connection('platform')->table('agent.float_limits')->where('agent_id', $agentId)->first(['max_balance', 'currency_code']);
        $currentBalance = $float ? $this->ledger->getBalance($float->ledger_account_reference) : 0;
        $limit = (int) ($limits?->max_balance ?? PHP_INT_MAX);

        if ($direction === 'credit' && ($currentBalance + $amount) > $limit) {
            return ErrorResource::make(
                'ADMINAPP_FLOAT_001',
                "Float adjustment of {$amount} would exceed the agent's float limit of {$limit}. Current balance: {$currentBalance}.",
                $correlationId,
                422,
            );
        }

        if ($direction === 'credit') {
            $txn = $this->floatFundingService->fund(
                agentId:        $agentId,
                amount:         $amount,
                currencyCode:   $limits?->currency_code ?? 'BWP',
                actorId:        $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } else {
            // Debit — admin-initiated float debit; cashOutByAdmin to be implemented in AgentFloat module.
            // @phpstan-ignore-next-line
            $txn = app(\App\Modules\AgentFloat\Application\Services\FloatCashOutService::class)
                ->cashOutByAdmin($agentId, $amount, $actorId, $idempotencyKey, $correlationId);
        }

        $newBalance = $direction === 'credit'
            ? $currentBalance + $amount
            : $currentBalance - $amount;

        $this->audit->record(new AuditEventData(
            action:        "adminapp.float.{$direction}",
            actorType:     'USER',
            resourceType:  'AgentFloatWallet',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $agentId,
            beforeState:   ['balance' => $currentBalance],
            afterState:    ['balance' => $newBalance, 'direction' => $direction, 'amount' => $amount],
        ));

        return response()->json([
            'data' => [
                'agent_id'                     => $agentId,
                'previous_balance_minor_units' => $currentBalance,
                'adjustment_minor_units'        => $amount,
                'new_balance_minor_units'       => $newBalance,
                'direction'                    => $direction,
                'adjusted_at'                  => now()->toIso8601ZuluString(),
            ],
        ]);
    }
}
