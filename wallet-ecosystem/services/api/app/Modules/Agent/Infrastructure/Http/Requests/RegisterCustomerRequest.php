<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone'      => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'id_type'    => ['required', 'string', 'in:national_id,passport'],
            'id_number'  => ['required', 'string', 'max:50'],
        ];
    }
}
