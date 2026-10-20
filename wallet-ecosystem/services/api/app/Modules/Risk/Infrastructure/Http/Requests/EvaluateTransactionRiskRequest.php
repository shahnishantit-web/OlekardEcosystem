<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EvaluateTransactionRiskRequest extends FormRequest
{
    private const TRANSACTION_TYPES = [
        'funding', 'transfer', 'withdrawal', 'bill_payment', 'qr_payment', 'commission',
    ];

    public function authorize(): bool
    {
        return true; // authorization handled by route middleware
    }

    public function rules(): array
    {
        return [
            'idempotency_key'      => ['required', 'string', 'max:128'],
            'transaction_reference' => ['required', 'string', 'max:128'],
            'transaction_type'     => ['required', 'string', Rule::in(self::TRANSACTION_TYPES)],
            'amount'               => ['required', 'numeric', 'gt:0'],
            'currency'             => ['required', 'string', Rule::in(['BWP'])],
            'customer_id'          => ['required', 'uuid'],
            'wallet_id'            => ['nullable', 'uuid'],
            'agent_id'             => ['nullable', 'uuid'],
            'beneficiary_id'       => ['nullable', 'uuid'],
            'device_id'            => ['nullable', 'string', 'max:128'],
            'ip_address'           => ['nullable', 'ip'],
            'kyc_tier'             => ['nullable', 'string', 'max:32'],
            'is_new_device'        => ['sometimes', 'boolean'],
            'is_new_beneficiary'   => ['sometimes', 'boolean'],
        ];
    }
}
