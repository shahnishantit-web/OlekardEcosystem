<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EvaluateAmountThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'           => ['required', 'numeric', 'gt:0'],
            'currency'         => ['required', 'string', Rule::in(['BWP'])],
            'customer_id'      => ['required', 'uuid'],
            'transaction_type' => ['required', 'string', Rule::in([
                'funding', 'transfer', 'withdrawal', 'bill_payment', 'qr_payment', 'commission',
            ])],
            'subject_id'       => ['nullable', 'uuid'],
        ];
    }
}
