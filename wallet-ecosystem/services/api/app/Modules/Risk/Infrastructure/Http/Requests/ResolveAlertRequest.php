<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alert_id'                   => ['required', 'uuid'],
            'decision'                   => ['required', 'string', 'in:clear,block,freeze_account,file_sar,allow'],
            'rationale'                  => ['required', 'string', 'min:10', 'max:5000'],
            'version'                    => ['required', 'integer', 'min:1'],
            'checker_id'                 => ['nullable', 'uuid'],
            'sar_narrative'              => ['nullable', 'string'],
            'sar_suspected_offences'     => ['nullable', 'array'],
            'sar_transaction_references' => ['nullable', 'array'],
            'sar_amount_involved'        => ['nullable', 'numeric', 'min:0.01'],
            'sar_currency'               => ['nullable', 'string', 'size:3'],
            'sar_period_start'           => ['nullable', 'date_format:Y-m-d'],
            'sar_period_end'             => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sar_period_start'],
        ];
    }
}
