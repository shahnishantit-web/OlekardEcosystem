<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Controllers;

use App\Modules\Integration\Application\DTOs\CreateApiClientData;
use App\Modules\Integration\Application\Services\AdminIntegrationService;
use App\Modules\Integration\Application\Services\ApiClientApplicationService;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\IpUtils;

final class AdminIntegrationController
{
    public function __construct(private readonly AdminIntegrationService $admin, private readonly ApiClientApplicationService $clients) {}

    public function clients(Request $r): JsonResponse
    {
        $r->validate(['integration_id' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json($this->admin->clients($r->query('integration_id')));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->safeClient(ApiClientModel::findOrFail($id))]);
    }

    public function create(Request $r): JsonResponse
    {
        $i = $r->validate(['name' => 'required|string|max:255', 'integration_id' => 'required|uuid', 'permissions' => 'present|array|max:100', 'permissions.*' => 'string|max:100|distinct', 'rate_limit_per_minute' => 'sometimes|integer|min:1|max:10000', 'ip_whitelist' => 'present|array|max:100', 'ip_whitelist.*' => ['string', 'max:50', function ($attribute, $value, $fail) {
            $ip = explode('/', $value)[0];
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ! IpUtils::checkIp($ip, $value)) {
                $fail('Use a valid IPv4/IPv6 address or CIDR.');
            }
        }]]);
        $this->admin->validatePartnerAndPermissions($i);
        $result = $this->clients->create(new CreateApiClientData($i['name'], $i['permissions'], $i['rate_limit_per_minute'] ?? 60, $i['integration_id'], $i['ip_whitelist']), $this->actor($r), $this->correlation($r));

        return $this->secret($result, 201);
    }

    public function rotate(Request $r, string $id): JsonResponse
    {
        abort_unless(ApiClientModel::whereKey($id)->exists(), 404);
        $i = $r->validate(['reason' => 'sometimes|nullable|string|max:255']);

        return $this->secret($this->clients->rotateSecret($id, $this->actor($r), $this->correlation($r), $i['reason'] ?? null));
    }

    public function revoke(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->revoke($id, $this->actor($r), $this->correlation($r))]);
    }

    public function providers(Request $r): JsonResponse
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'provider_type' => 'sometimes|in:otp,sms,email,kyc,payment,bank,mobile_money']);

        return response()->json($this->admin->providers($r->query('provider_type')));
    }

    public function health(string $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->health($id)]);
    }

    public function deliveries(Request $r): JsonResponse
    {
        $i = $r->validate(['status' => 'sometimes|in:delivered,failed,retrying', 'event_type' => 'sometimes|string|max:100', 'page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json($this->admin->deliveries($i));
    }

    public function retry(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->retry($id, $this->actor($r), $this->correlation($r))], 202);
    }

    public function quarantine(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->quarantine($id, $this->actor($r), $this->correlation($r))]);
    }

    private function secret(array $result, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->admin->safeClient($result['client']) + ['api_key' => $result['client']->api_key, 'raw_secret' => $result['raw_secret']]], $status)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function actor(Request $r): string
    {
        $id = $r->attributes->get('identity.user_id');
        abort_unless(is_string($id) && Uuid::isValid($id), 401);

        return $id;
    }

    private function correlation(Request $r): string
    {
        $id = $r->header('X-Correlation-ID');

        return is_string($id) && Uuid::isValid($id) ? $id : (string) Uuid::uuid7();
    }
}
