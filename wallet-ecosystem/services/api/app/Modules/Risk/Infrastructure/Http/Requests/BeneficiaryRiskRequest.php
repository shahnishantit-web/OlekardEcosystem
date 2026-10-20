<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BeneficiaryRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_id'    => ['required', 'uuid'],
            'beneficiary_name'  => ['required', 'string', 'max:500'],
            'beneficiary_type'  => ['required', 'string', 'max:30'],
            'customer_id'       => ['required', 'uuid'],
            'is_new_beneficiary' => ['required', 'boolean'],
            'date_of_birth'     => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
