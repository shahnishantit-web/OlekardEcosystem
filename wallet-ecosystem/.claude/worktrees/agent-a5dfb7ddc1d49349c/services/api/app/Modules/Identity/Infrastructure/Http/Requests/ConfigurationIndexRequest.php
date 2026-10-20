<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for GET /v1/admin/platform/configuration/security.
 * Permission: platform.configuration.view — enforced as actor_type=ADMIN check
 * (E04 full RBAC not yet shipped; inline check matches pattern from AdminSessionController).
 */
final class ConfigurationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return strtoupper((string) $this->attributes->get('identity.actor_type', '')) === 'ADMIN';
    }

    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): never
    {
        abort(response()->json([
            'success' => false,
            'error'   => [
                'code'     => 'IDENTITY_010',
                'message'  => 'Insufficient permissions.',
                'trace_id' => $this->header('X-Correlation-ID', ''),
            ],
        ], 403));
    }
}
