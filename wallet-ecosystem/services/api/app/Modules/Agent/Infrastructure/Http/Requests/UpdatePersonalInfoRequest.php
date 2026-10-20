<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePersonalInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name'      => ['required', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'national_id_number' => ['required', 'string', 'max:50'],
            'national_id_type'   => ['required', 'string', 'in:national_id,passport,drivers_license'],
            'date_of_birth'   => ['required', 'date', 'before:today'],
            'phone'           => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'address'         => ['required', 'array'],
            'address.street'  => ['required', 'string', 'max:255'],
            'address.city'    => ['required', 'string', 'max:100'],
            'address.country' => ['required', 'string', 'size:2'],
        ];
    }
}
