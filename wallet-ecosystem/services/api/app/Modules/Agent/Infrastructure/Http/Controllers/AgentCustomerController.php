<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Controllers;

use App\Modules\Agent\Application\Services\AgentCustomerService;
use App\Modules\Agent\Domain\Exceptions\AgentNotActiveException;
use App\Modules\Agent\Domain\Exceptions\DuplicatePhoneException;
use App\Modules\Agent\Domain\Exceptions\KycAlreadyCompletedException;
use App\Modules\Agent\Domain\Exceptions\SearchQueryTooShortException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use App\Modules\Agent\Infrastructure\Http\Requests\RegisterCustomerRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\SearchCustomersRequest;
use App\Modules\Agent\Infrastructure\Http\Resources\AgentTransactionResource;
use App\Modules\Agent\Infrastructure\Http\Resources\CustomerSummaryResource;
use App\Modules\Agent\Infrastructure\Http\Resources\CustomerWalletViewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentCustomerController extends Controller
{
    public function __construct(
        private readonly AgentCustomerService $service,
    ) {}

    // T080 ————————————————————————————————————————————————
    public function search(SearchCustomersRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        $validated = $request->validated();
        $page      = (int) ($validated['page'] ?? 1);
        $perPage   = (int) ($validated['per_page'] ?? 20);

        try {
            $paginator = $this->service->searchCustomers(
                agentId:       $id,
                query:         $validated['search'],
                page:          $page,
                perPage:       $perPage,
                actorId:       $actorId,
                correlationId: $correlationId,
            );
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_SUSPENDED', 'Agent account is suspended.', 403, $correlationId);
        } catch (SearchQueryTooShortException $e) {
            return $this->errorResponse('SEARCH_TOO_SHORT', $e->getMessage(), 422, $correlationId);
        }

        $items = collect($paginator->items())
            ->map(fn ($row) => (new CustomerSummaryResource((object) (array) $row))->toArray($request))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'error'   => null,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    // T086 ————————————————————————————————————————————————
    public function register(RegisterCustomerRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $customer = $this->service->registerCustomer(
                agentId:       $id,
                data:          $request->validated(),
                idempotencyKey: $idempotencyKey,
                actorId:       $actorId,
                correlationId: $correlationId,
            );
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_SUSPENDED', 'Agent account is suspended.', 403, $correlationId);
        } catch (DuplicatePhoneException) {
            return $this->errorResponse('PHONE_ALREADY_REGISTERED', 'A user with this phone number already exists.', 422, $correlationId);
        }

        $isReplay = isset($customer['_replay']) && $customer['_replay'];
        unset($customer['_replay']);

        return response()->json([
            'success' => true,
            'data'    => $customer,
            'error'   => null,
            'meta'    => null,
        ], $isReplay ? 200 : 201, ['X-Correlation-ID' => $correlationId]);
    }

    // T091 ————————————————————————————————————————————————
    public function initiateCustomerKyc(Request $request, string $id, string $cid): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $this->service->initiateCustomerKyc(
                agentId:       $id,
                customerId:    $cid,
                actorId:       $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED_CUSTOMER_ACCESS', 'Agent is not authorized to access this customer.', 403, $correlationId);
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_SUSPENDED', 'Agent account is suspended.', 403, $correlationId);
        } catch (KycAlreadyCompletedException) {
            return $this->errorResponse('KYC_ALREADY_COMPLETED', 'Customer KYC has already been completed.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'error'   => null,
            'meta'    => null,
        ], 202, ['X-Correlation-ID' => $correlationId]);
    }

    // T095 ————————————————————————————————————————————————
    public function viewWallet(Request $request, string $id, string $cid): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        try {
            $walletData = $this->service->viewCustomerWallet(
                agentId:       $id,
                customerId:    $cid,
                actorId:       $actorId,
                correlationId: $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED_CUSTOMER_ACCESS', 'Agent is not authorized to access this customer.', 403, $correlationId);
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_SUSPENDED', 'Agent account is suspended.', 403, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new CustomerWalletViewResource($walletData))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    // T100 ————————————————————————————————————————————————
    public function viewTransactions(Request $request, string $id, string $cid): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        $page    = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        try {
            $paginator = $this->service->viewCustomerTransactions(
                agentId:       $id,
                customerId:    $cid,
                page:          $page,
                perPage:       $perPage,
                actorId:       $actorId,
                correlationId: $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED_CUSTOMER_ACCESS', 'Agent is not authorized to access this customer.', 403, $correlationId);
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_SUSPENDED', 'Agent account is suspended.', 403, $correlationId);
        }

        $items = collect($paginator->items())
            ->map(fn ($row) => (new AgentTransactionResource((object) (array) $row))->toArray($request))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'error'   => null,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
