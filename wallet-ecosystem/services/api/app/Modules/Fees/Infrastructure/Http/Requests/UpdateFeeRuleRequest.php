<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFeeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'valid_to'       => ['nullable', 'date', 'after:valid_from'],
            'min_fee_minor'  => ['nullable', 'integer', 'min:1'],
            'max_fee_minor'  => ['nullable', 'integer', 'min:1', 'gte:min_fee_minor'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_fee_minor.gte' => 'FEE_BOUNDS_CONFLICT: max_fee_minor must be >= min_fee_minor.',
        ];
    }
}
