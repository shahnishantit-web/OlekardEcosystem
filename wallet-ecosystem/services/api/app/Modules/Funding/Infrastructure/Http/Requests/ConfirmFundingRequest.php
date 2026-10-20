<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmFundingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_pin'     => ['required', 'string', 'min:4', 'max:8'],
            'accepted_fee_amount' => ['required', 'integer', 'min:0'],
        ];
    }
}
