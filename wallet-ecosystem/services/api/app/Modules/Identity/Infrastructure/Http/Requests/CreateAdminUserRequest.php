<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use App\Modules\Identity\Domain\Rules\ConfiguredPassword;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CreateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', new ConfiguredPassword(true)],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        abort(response()->json([
            'success' => false,
            'error' => [
                'code' => 'IDENTITY_001',
                'message' => 'Validation failed.',
                'details' => $validator->errors()->toArray(),
                'trace_id' => $this->header('X-Correlation-ID', ''),
            ],
        ], 400));
    }
}
