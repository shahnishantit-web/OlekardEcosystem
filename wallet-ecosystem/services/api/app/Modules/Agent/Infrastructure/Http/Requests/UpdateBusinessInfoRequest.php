<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBusinessInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'trading_name'        => ['sometimes', 'string', 'max:200'],
            'registration_number' => ['sometimes', 'string', 'max:50'],
            'tax_number'          => ['sometimes', 'string', 'max:50'],
            'business_address'    => ['sometimes', 'array'],
            'business_address.street'  => ['required_with:business_address', 'string', 'max:255'],
            'business_address.city'    => ['required_with:business_address', 'string', 'max:100'],
            'business_address.country' => ['required_with:business_address', 'string', 'size:2'],
        ];
    }
}
