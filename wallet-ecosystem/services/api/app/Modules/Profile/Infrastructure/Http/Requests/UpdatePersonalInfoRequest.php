<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates personal info update fields.
 *
 * Forbidden fields (system-managed / identity-owned) must NEVER reach the service layer.
 * Any forbidden key in the request body → 400 FIELD_NOT_PERMITTED.
 * Field format errors → 400 VALIDATION_ERROR.
 * Minimum age business rule → handled by ProfileService (422 MINIMUM_AGE_VIOLATION).
 */
final class UpdatePersonalInfoRequest extends FormRequest
{
    private const FORBIDDEN_KEYS = [
        'id', 'user_id', 'email', 'mobile_number', 'account_status',
        'created_at', 'updated_at', 'profile_photo_key',
    ];

    public function authorize(): bool
    {
        return true; // Authorization enforced by identity.auth + profile.permission middleware
    }

    public function rules(): array
    {
        return [
            'first_name'          => ['sometimes', 'string', 'max:100'],
            'last_name'           => ['sometimes', 'string', 'max:100'],
            'date_of_birth'       => ['sometimes', 'date_format:Y-m-d'],
            'gender'              => ['sometimes', 'string', 'in:male,female,non_binary,prefer_not_to_say'],
            'address'             => ['sometimes', 'array'],
            'address.street'      => ['sometimes', 'string', 'max:255'],
            'address.city'        => ['sometimes', 'string', 'max:100'],
            'address.region'      => ['sometimes', 'string', 'max:100'],
            'address.postal_code' => ['sometimes', 'string', 'max:20'],
            'address.country'     => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'preferred_language'  => ['sometimes', 'string', 'max:5'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Required Idempotency-Key header
            if (empty($this->header('Idempotency-Key'))) {
                $v->errors()->add('Idempotency-Key', 'The Idempotency-Key header is required.');
            }

            // Forbidden field detection (top-level request keys only)
            foreach (array_keys($this->all()) as $key) {
                if (in_array($key, self::FORBIDDEN_KEYS, true) || str_starts_with((string) $key, 'pending_')) {
                    $v->errors()->add('_field_not_permitted', $key);
                    return;
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $traceId = $this->header('X-Correlation-ID', '');

        if ($validator->errors()->has('_field_not_permitted')) {
            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'error'   => [
                        'code'     => 'FIELD_NOT_PERMITTED',
                        'message'  => 'The request contains a field that is not permitted.',
                        'trace_id' => $traceId,
                    ],
                ], 400)
            );
        }

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error'   => [
                    'code'     => 'VALIDATION_ERROR',
                    'message'  => 'The provided data failed validation.',
                    'trace_id' => $traceId,
                    'fields'   => $validator->errors()->toArray(),
                ],
            ], 400)
        );
    }
}
