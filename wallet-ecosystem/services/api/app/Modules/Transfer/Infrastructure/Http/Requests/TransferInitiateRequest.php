<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferInitiateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lookup_token'  => ['required', 'uuid'],
            'wallet_id'     => ['required', 'uuid'],
            'gross_amount'  => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if (! $this->hasHeader('Idempotency-Key')) {
                $v->errors()->add('idempotency_key', 'The Idempotency-Key header is required.');
            }
        });
    }
}
