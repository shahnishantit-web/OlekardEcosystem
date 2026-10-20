<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use App\Modules\Service\Domain\Enums\ServiceFeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'string', 'max:255'],
            'category'        => ['sometimes', 'string', 'max:100'],
            'description'     => ['nullable', 'string', 'max:1000'],
            'currency_code'   => ['sometimes', 'string', 'size:3'],
            'min_amount'      => ['sometimes', 'integer', 'min:1'],
            'max_amount'      => ['sometimes', 'integer', 'min:1', 'gte:min_amount'],
            'fee_type'        => ['sometimes', Rule::enum(ServiceFeeType::class)],
            'fee_value'                           => ['sometimes', 'integer', 'min:0'],
            'required_fields'                     => ['sometimes', 'nullable', 'array'],
            'required_fields.*.name'              => ['required_with:required_fields', 'string', 'max:100'],
            'required_fields.*.label'             => ['required_with:required_fields', 'string', 'max:255'],
            'required_fields.*.placeholder'       => ['nullable', 'string', 'max:255'],
            'required_fields.*.validation_regex'  => ['nullable', 'string', 'max:500'],
            'required_fields.*.max_length'        => ['nullable', 'integer', 'min:1'],
        ];
    }
}
