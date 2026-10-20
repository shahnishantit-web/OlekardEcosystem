<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for PUT /v1/admin/platform/configuration/security/{key}.
 * Permission: platform.configuration.update — enforced as actor_type=ADMIN check.
 * Validates required fields; typed value validation is handled by ConfigurationAdminService.
 */
final class ConfigurationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return strtoupper((string) $this->attributes->get('identity.actor_type', '')) === 'ADMIN';
    }

    public function rules(): array
    {
        return [
            'value'   => ['required', 'string'],
            'version' => ['required', 'string'],
            'reason'  => ['nullable', 'string', 'max:500'],
        ];
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

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): never
    {
        $correlationId = $this->header('X-Correlation-ID', '');
        abort(response()->json([
            'success' => false,
            'error'   => [
                'code'     => 'IDENTITY_001',
                'message'  => 'Validation failed.',
                'details'  => $validator->errors()->toArray(),
                'trace_id' => $correlationId,
            ],
        ], 400));
    }
}
