<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'string', 'max:255'],
            'rule_type'        => ['sometimes', 'string', 'in:flat,percentage'],
            'rate'             => ['sometimes', 'integer', 'min:1'],
            'applies_to'       => ['sometimes', 'string', 'in:cash_in,cash_out,both'],
            'agent_tier'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'volume_threshold' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_amount'       => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_amount'       => ['sometimes', 'nullable', 'integer', 'min:0'],
            'valid_from'       => ['sometimes', 'date'],
            'valid_to'         => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
