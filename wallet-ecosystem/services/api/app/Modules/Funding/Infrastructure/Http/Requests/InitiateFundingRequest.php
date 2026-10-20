<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Requests;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InitiateFundingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization enforced by route middleware (identity.auth + permission gate)
    }

    public function rules(): array
    {
        return [
            'wallet_id'      => ['required', 'uuid'],
            'gross_amount'   => ['required', 'integer', 'min:1'],
            'currency_code'  => ['required', 'string', 'size:3'],
            'funding_method' => ['required', Rule::enum(FundingMethod::class)],
        ];
    }
}
