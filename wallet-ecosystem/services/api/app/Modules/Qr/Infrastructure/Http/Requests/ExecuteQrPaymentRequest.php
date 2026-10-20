<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExecuteQrPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_code_id'         => ['required', 'uuid'],
            'customer_wallet_id' => ['required', 'uuid'],
            'amount'             => ['nullable', 'numeric', 'min:0.01'],
            'currency'           => ['required', 'string', 'size:3'],
            'step_up_token'      => ['nullable', 'string'],
        ];
    }
}
