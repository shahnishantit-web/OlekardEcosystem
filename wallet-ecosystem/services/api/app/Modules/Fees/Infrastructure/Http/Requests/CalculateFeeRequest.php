<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Requests;

use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CalculateFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_type' => ['required', Rule::enum(FeeTransactionType::class)],
            'amount_minor'     => ['required', 'integer', 'min:1'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'subject_type'     => ['required', Rule::enum(FeeSubjectType::class)],
            'subject_id'       => [
                Rule::requiredIf($this->input('subject_type') !== FeeSubjectType::Global->value),
                'nullable',
                'uuid',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_type.enum' => 'UNSUPPORTED_TRANSACTION_TYPE: The provided transaction_type is not supported.',
        ];
    }
}
