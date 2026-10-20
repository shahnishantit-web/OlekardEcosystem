<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AmountLimitRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'threshold_amount'  => ['required', 'numeric', 'min:0.01'],
            'action'            => ['required', 'in:block,review,flag'],
            'applies_to'        => ['required', 'in:global,customer,agent,wallet'],
            'subject_id'        => ['nullable', 'uuid'],
            'risk_score_weight' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'effective_from'    => ['nullable', 'date'],
            'effective_until'   => ['nullable', 'date', 'after:effective_from'],
            'description'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
