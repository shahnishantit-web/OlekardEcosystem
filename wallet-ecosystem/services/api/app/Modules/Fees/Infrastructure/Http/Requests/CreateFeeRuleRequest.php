<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Requests;

use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use App\Modules\Fees\Domain\Enums\FeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateFeeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $feeType = $this->input('fee_type');

        return [
            'fee_type'            => ['required', Rule::enum(FeeType::class)],
            'transaction_type'    => ['required', Rule::enum(FeeTransactionType::class)],
            'currency'            => ['nullable', 'string', 'size:3'],
            'subject_type'        => ['required', Rule::enum(FeeSubjectType::class)],
            'subject_id'          => [
                Rule::requiredIf($this->input('subject_type') !== FeeSubjectType::Global->value),
                'nullable',
                'uuid',
            ],
            'rate'                => [
                Rule::requiredIf($feeType === FeeType::Percentage->value),
                'nullable',
                'numeric',
                'gt:0',
                'lte:100',
            ],
            'amount_minor'        => [
                Rule::requiredIf($feeType === FeeType::Fixed->value),
                'nullable',
                'integer',
                'min:1',
            ],
            'valid_from'          => ['required', 'date'],
            'valid_to'            => ['nullable', 'date', 'after:valid_from'],
            'pricing_version_id'  => ['nullable', 'uuid'],
            'min_fee_minor'       => ['nullable', 'integer', 'min:1'],
            'max_fee_minor'       => ['nullable', 'integer', 'min:1', 'gte:min_fee_minor'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $feeType = $this->input('fee_type');
            if ($feeType === FeeType::Fixed->value) {
                if ($this->filled('min_fee_minor')) {
                    $v->errors()->add('min_fee_minor', 'MIN_FEE_NOT_APPLICABLE_TO_FIXED_RULES: min_fee_minor is not applicable to fixed fee rules.');
                }
                if ($this->filled('max_fee_minor')) {
                    $v->errors()->add('max_fee_minor', 'MAX_FEE_NOT_APPLICABLE_TO_FIXED_RULES: max_fee_minor is not applicable to fixed fee rules.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'rate.gt'            => 'INVALID_FEE_RATE: rate must be greater than 0.',
            'rate.lte'           => 'INVALID_FEE_RATE: rate must not exceed 100.',
            'amount_minor.min'   => 'INVALID_FEE_AMOUNT: amount_minor must be at least 1.',
            'max_fee_minor.gte'  => 'FEE_BOUNDS_CONFLICT: max_fee_minor must be >= min_fee_minor.',
        ];
    }
}
