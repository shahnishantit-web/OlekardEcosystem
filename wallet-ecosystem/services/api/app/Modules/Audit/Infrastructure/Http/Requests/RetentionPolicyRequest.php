<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RetentionPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data_category'           => ['required', 'string', 'max:64'],
            'minimum_retention_days'  => ['required', 'integer', 'min:1'],
            'post_retention_action'   => ['required', 'in:archive,purge'],
        ];
    }
}
