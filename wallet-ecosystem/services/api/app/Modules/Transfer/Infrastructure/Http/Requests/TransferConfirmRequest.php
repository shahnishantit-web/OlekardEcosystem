<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_pin' => ['required', 'string'],
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
