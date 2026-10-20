<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAdminUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): never
    {
        abort(response()->json([
            'success' => false,
            'error'   => [
                'code'     => 'IDENTITY_001',
                'message'  => 'Validation failed.',
                'details'  => $validator->errors()->toArray(),
                'trace_id' => $this->header('X-Correlation-ID', ''),
            ],
        ], 400));
    }
}
