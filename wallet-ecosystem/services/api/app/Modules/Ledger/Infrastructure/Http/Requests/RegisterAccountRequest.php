<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ledger_account_reference' => ['required', 'string'],
            'account_type'             => ['required', 'string', 'in:asset,liability,income,expense,equity'],
            'currency_code'            => ['required', 'string', 'max:10'],
            'owner_reference'          => ['required', 'uuid'],
            'owner_type'               => ['required', 'string', 'in:customer_pocket,system_float,system_revenue,system_fee,agent_commission,system_suspense'],
        ];
    }
}
