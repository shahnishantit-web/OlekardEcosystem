<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key'  => ['required', 'string', 'max:128'],
            'adjustment_type'  => ['required', 'string', 'in:credit,debit'],
            'amount'           => ['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'currency'         => ['required', 'string', 'in:BWP'],
            'reason'           => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
