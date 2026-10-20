<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use App\Modules\Service\Domain\Enums\ServiceFeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'category'         => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'provider_code'    => ['required', 'string', 'max:100'],
            'currency_code'    => ['sometimes', 'string', 'size:3'],
            'min_amount'       => ['required', 'integer', 'min:1'],
            'max_amount'       => ['required', 'integer', 'min:1', 'gte:min_amount'],
            'fee_type'         => ['required', Rule::enum(ServiceFeeType::class)],
            'fee_value'                           => ['required', 'integer', 'min:0'],
            'required_fields'                     => ['nullable', 'array'],
            'required_fields.*.name'              => ['required', 'string', 'max:100'],
            'required_fields.*.label'             => ['required', 'string', 'max:255'],
            'required_fields.*.placeholder'       => ['nullable', 'string', 'max:255'],
            'required_fields.*.validation_regex'  => ['nullable', 'string', 'max:500'],
            'required_fields.*.max_length'        => ['nullable', 'integer', 'min:1'],
        ];
    }
}
