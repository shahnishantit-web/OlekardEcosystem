<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class VerifyContactChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced by identity.auth middleware
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'uuid'],
            'otp'          => ['required', 'string', 'min:1'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (empty($this->header('Idempotency-Key'))) {
                $v->errors()->add('Idempotency-Key', 'The Idempotency-Key header is required.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $traceId = $this->header('X-Correlation-ID', '');

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
