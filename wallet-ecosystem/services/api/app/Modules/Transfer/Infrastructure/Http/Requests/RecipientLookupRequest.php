<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecipientLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number'  => ['sometimes', 'string', 'max:20'],
            'wallet_number' => ['sometimes', 'string', 'max:50'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $hasPhone  = $this->has('phone_number')  && $this->input('phone_number') !== null;
            $hasWallet = $this->has('wallet_number') && $this->input('wallet_number') !== null;

            if (! $hasPhone && ! $hasWallet) {
                $v->errors()->add('phone_number', 'Exactly one of phone_number or wallet_number is required.');
            }

            if ($hasPhone && $hasWallet) {
                $v->errors()->add('phone_number', 'Provide either phone_number or wallet_number, not both.');
            }
        });
    }
}
