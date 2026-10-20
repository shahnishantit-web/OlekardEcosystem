<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for GET /v1/admin/platform/configuration/security.
 * Permission: configuration.policy.view — enforced by identity.require-permission middleware (E04).
 */
final class ConfigurationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
