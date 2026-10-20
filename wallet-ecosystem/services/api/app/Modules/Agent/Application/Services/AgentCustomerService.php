<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Domain\Exceptions\AgentNotActiveException;
use App\Modules\Agent\Domain\Exceptions\DuplicatePhoneException;
use App\Modules\Agent\Domain\Exceptions\KycAlreadyCompletedException;
use App\Modules\Agent\Domain\Exceptions\SearchQueryTooShortException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use App\Modules\Agent\Infrastructure\Models\AgentCustomerRelationshipModel;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentCustomerService
{
    public function __construct(
        private readonly IdempotencyServiceInterface   $idempotency,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    // T076 ————————————————————————————————————————————————
    public function assertLinked(
        string $agentId,
        string $customerId,
        string $actorId,
        string $correlationId,
    ): void {
        $exists = DB::connection('platform')
            ->table('agent.agent_customer_relationships')
            ->where('agent_id', $agentId)
            ->where('customer_id', $customerId)
            ->exists();

        if (! $exists) {
            // Publish security event directly (cannot use OutboxEventPublisher outside transaction)
            DB::connection('platform')->table('platform.outbox_events')->insert([
                'id'             => Uuid::uuid7()->toString(),
                'aggregate_id'   => $customerId,
                'aggregate_type' => 'unauthorized_access',
                'correlation_id' => $correlationId,
                'event_name'     => 'security.unauthorized_access_attempt',
                'event_version'  => '1.0.0',
                'payload'        => json_encode([
                    'actor_id'       => $actorId,
                    'resource'       => 'agent.customer',
                    'resource_id'    => $customerId,
                    'agent_id'       => $agentId,
                    'occurred_at'    => now()->toIso8601String(),
                    'correlation_id' => $correlationId,
                ]),
                'metadata'       => json_encode(['correlation_id' => $correlationId]),
                'created_at'     => now(),
            ]);

            throw new UnauthorizedCustomerAccessException();
        }
    }

    // T077 ————————————————————————————————————————————————
    public function assertAgentActive(string $agentId): AgentModel
    {
        /** @var AgentModel|null $agent */
        $agent = AgentModel::on('platform')->find($agentId);

        if ($agent === null || $agent->status->value === 'suspended') {
            throw new AgentNotActiveException();
        }

        return $agent;
    }

    // T078 ————————————————————————————————————————————————
    public function searchCustomers(
        string $agentId,
        string $query,
        int    $page,
        int    $perPage,
        string $actorId,
        string $correlationId,
    ): LengthAwarePaginator {
        $minLength = (int) ($this->config->get('agent.customer.search.min_length') ?? 3);

        if (mb_strlen($query) < $minLength) {
            throw new SearchQueryTooShortException($minLength);
        }

        $this->assertAgentActive($agentId);

        $likeQuery = '%' . $query . '%';

        $paginator = DB::connection('platform')
            ->table('agent.agent_customer_relationships')
            ->where('agent_id', $agentId)
            ->where(function ($q) use ($likeQuery) {
                $q->where('first_name', 'like', $likeQuery)
                    ->orWhere('last_name', 'like', $likeQuery)
                    ->orWhere('mobile_number', 'like', $likeQuery);
            })
            ->select(
                'customer_id as id',
                'first_name',
                'last_name',
                'national_id_number',
                'national_id_type',
                'mobile_number as phone',
                'customer_status as status'
            )
            ->paginate($perPage, ['*'], 'page', $page);

        DB::connection('platform')->table('platform.outbox_events')->insert([
            'id'             => Uuid::uuid7()->toString(),
            'aggregate_id'   => $agentId,
            'aggregate_type' => 'agent',
            'correlation_id' => $correlationId,
            'event_name'     => 'agent.customer.searched',
            'event_version'  => '1.0.0',
            'payload'        => json_encode([
                'agent_id'       => $agentId,
                'actor_id'       => $actorId,
                'query'          => $query,
                'result_count'   => $paginator->total(),
                'occurred_at'    => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ]),
            'metadata'       => json_encode(['correlation_id' => $correlationId]),
            'created_at'     => now(),
        ]);

        return $paginator;
    }

    // T084 ————————————————————————————————————————————————
    public function registerCustomer(
        string $agentId,
        array  $data,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
    ): array {
        $storeKey = "agent_register_customer:{$agentId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $actorId);
        if ($cached !== null) {
            return array_merge(['_replay' => true], $cached['body'] ?? []);
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $actorId,
            hash('sha256', "POST:agent_register_customer:{$agentId}:{$idempotencyKey}"),
            'POST',
            "/api/v1/agents/{$agentId}/customers",
            $correlationId,
        );

        $this->assertAgentActive($agentId);

        $existingUser = DB::connection('identity')
            ->table('identity.users')
            ->where('mobile_number', $data['phone'])
            ->first();

        if ($existingUser !== null) {
            $this->idempotency->recordFailure($lock, new DuplicatePhoneException());
            throw new DuplicatePhoneException();
        }

        $customerId = Uuid::uuid7()->toString();

        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $customerId,
            'mobile_number' => $data['phone'],
            'status'        => 'pending_kyc',
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result = DB::connection('platform')->transaction(function () use (
            $agentId, $customerId, $actorId, $correlationId, $data, $lock
        ) {
            AgentCustomerRelationshipModel::on('platform')->create([
                'id'                => Uuid::uuid7()->toString(),
                'agent_id'          => $agentId,
                'customer_id'       => $customerId,
                'registered_by'     => $actorId,
                'registered_at'     => now(),
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'],
                'mobile_number'     => $data['phone'],
                'national_id_type'  => $data['id_type'],
                'national_id_number' => $data['id_number'],
                'customer_status'   => 'pending_kyc',
            ]);

            DB::connection('platform')->table('platform.outbox_events')->insert([
                'id'             => Uuid::uuid7()->toString(),
                'aggregate_id'   => $customerId,
                'aggregate_type' => 'customer',
                'correlation_id' => $correlationId,
                'event_name'     => 'agent.customer.registered',
                'event_version'  => '1.0.0',
                'payload'        => json_encode([
                    'agent_id'       => $agentId,
                    'customer_id'    => $customerId,
                    'actor_id'       => $actorId,
                    'occurred_at'    => now()->toIso8601String(),
                    'correlation_id' => $correlationId,
                ]),
                'metadata'       => json_encode(['correlation_id' => $correlationId]),
                'created_at'     => now(),
            ]);

            $response = [
                'id'         => $customerId,
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'phone'      => $data['phone'],
                'status'     => 'pending_kyc',
            ];

            $this->idempotency->recordResponse($lock, 201, $response);

            return $response;
        });

        return $result;
    }

    // T090 ————————————————————————————————————————————————
    public function initiateCustomerKyc(
        string $agentId,
        string $customerId,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): void {
        $storeKey = "agent_customer_kyc:{$agentId}:{$customerId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $actorId);
        if ($cached !== null) {
            return;
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $actorId,
            hash('sha256', "POST:agent_customer_kyc:{$agentId}:{$customerId}:{$idempotencyKey}"),
            'POST',
            "/api/v1/agents/{$agentId}/customers/{$customerId}/kyc",
            $correlationId,
        );

        $this->assertAgentActive($agentId);
        $this->assertLinked($agentId, $customerId, $actorId, $correlationId);

        $relationship = DB::connection('platform')
            ->table('agent.agent_customer_relationships')
            ->where('agent_id', $agentId)
            ->where('customer_id', $customerId)
            ->first();

        if ($relationship !== null && $relationship->customer_status === 'kyc_verified') {
            $this->idempotency->recordFailure($lock, new KycAlreadyCompletedException());
            throw new KycAlreadyCompletedException();
        }

        DB::connection('platform')->transaction(function () use (
            $agentId, $customerId, $actorId, $correlationId, $lock
        ) {
            DB::connection('platform')->table('platform.outbox_events')->insert([
                'id'             => Uuid::uuid7()->toString(),
                'aggregate_id'   => $customerId,
                'aggregate_type' => 'customer',
                'correlation_id' => $correlationId,
                'event_name'     => 'agent.customer.kyc_initiated',
                'event_version'  => '1.0.0',
                'payload'        => json_encode([
                    'agent_id'       => $agentId,
                    'customer_id'    => $customerId,
                    'actor_id'       => $actorId,
                    'occurred_at'    => now()->toIso8601String(),
                    'correlation_id' => $correlationId,
                ]),
                'metadata'       => json_encode(['correlation_id' => $correlationId]),
                'created_at'     => now(),
            ]);

            $this->idempotency->recordResponse($lock, 202, ['initiated' => true]);
        });
    }

    // T094 ————————————————————————————————————————————————
    public function viewCustomerWallet(
        string $agentId,
        string $customerId,
        string $actorId,
        string $correlationId,
    ): array {
        $this->assertAgentActive($agentId);
        $this->assertLinked($agentId, $customerId, $actorId, $correlationId);

        $wallet = DB::connection('platform')
            ->table('wallet.wallets')
            ->where('owner_id', $customerId)
            ->first();

        $walletId = $wallet?->id;
        $isFrozen = $wallet?->status === 'frozen';

        DB::connection('platform')->table('platform.outbox_events')->insert([
            'id'             => Uuid::uuid7()->toString(),
            'aggregate_id'   => $customerId,
            'aggregate_type' => 'customer',
            'correlation_id' => $correlationId,
            'event_name'     => 'agent.customer.wallet_viewed',
            'event_version'  => '1.0.0',
            'payload'        => json_encode([
                'agent_id'       => $agentId,
                'customer_id'    => $customerId,
                'actor_id'       => $actorId,
                'wallet_id'      => $walletId,
                'occurred_at'    => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ]),
            'metadata'       => json_encode(['correlation_id' => $correlationId]),
            'created_at'     => now(),
        ]);

        return [
            'wallet_id'          => $walletId,
            'status'             => $wallet?->status,
            'tier'               => null,
            'is_frozen'          => $isFrozen,
            'balance_disclosure' => null,
        ];
    }

    // T099 ————————————————————————————————————————————————
    public function viewCustomerTransactions(
        string $agentId,
        string $customerId,
        int    $page,
        int    $perPage,
        string $actorId,
        string $correlationId,
    ): LengthAwarePaginator {
        $this->assertAgentActive($agentId);
        $this->assertLinked($agentId, $customerId, $actorId, $correlationId);

        $paginator = DB::connection('platform')
            ->table('transaction.transaction_projections')
            ->where('customer_id', $customerId)
            ->whereRaw("metadata->>'source_agent_id' = ?", [$agentId])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        DB::connection('platform')->table('platform.outbox_events')->insert([
            'id'             => Uuid::uuid7()->toString(),
            'aggregate_id'   => $customerId,
            'aggregate_type' => 'customer',
            'correlation_id' => $correlationId,
            'event_name'     => 'agent.customer.transactions_viewed',
            'event_version'  => '1.0.0',
            'payload'        => json_encode([
                'agent_id'       => $agentId,
                'customer_id'    => $customerId,
                'actor_id'       => $actorId,
                'occurred_at'    => now()->toIso8601String(),
                'correlation_id' => $correlationId,
            ]),
            'metadata'       => json_encode(['correlation_id' => $correlationId]),
            'created_at'     => now(),
        ]);

        return $paginator;
    }
}
