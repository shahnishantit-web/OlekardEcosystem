<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug'         => ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
            'display_name' => ['required', 'string', 'max:100'],
            'description'  => ['nullable', 'string', 'max:500'],
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
