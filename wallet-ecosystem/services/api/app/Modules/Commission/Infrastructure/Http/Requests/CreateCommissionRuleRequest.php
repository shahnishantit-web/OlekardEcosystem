<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'rule_type'        => ['required', 'string', 'in:flat,percentage'],
            'rate'             => ['required', 'integer', 'min:1'],
            'applies_to'       => ['required', 'string', 'in:cash_in,cash_out,both'],
            'agent_tier'       => ['nullable', 'string', 'max:50'],
            'volume_threshold' => ['nullable', 'integer', 'min:1'],
            'min_amount'       => ['nullable', 'integer', 'min:0'],
            'max_amount'       => ['nullable', 'integer', 'min:0'],
            'valid_from'       => ['required', 'date'],
            'valid_to'         => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
