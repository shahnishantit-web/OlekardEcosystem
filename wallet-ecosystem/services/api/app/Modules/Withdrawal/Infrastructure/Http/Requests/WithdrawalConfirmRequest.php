<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WithdrawalConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_pin'    => ['required', 'string'],
            'quoted_fee_amount'  => ['required', 'integer', 'min:0'],
        ];
    }
}
