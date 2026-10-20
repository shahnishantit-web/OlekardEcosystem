<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateSettlementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'recon_batch_id'  => ['required', 'uuid'],
            'settlement_type' => ['required', Rule::in(['provider_settlement', 'bank_settlement'])],
            'period_start'    => ['required', 'date_format:Y-m-d'],
            'period_end'      => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'currency_code'   => ['required', 'string', 'size:3'],
            'provider_code'   => ['nullable', 'string', 'max:50'],
        ];
    }
}
