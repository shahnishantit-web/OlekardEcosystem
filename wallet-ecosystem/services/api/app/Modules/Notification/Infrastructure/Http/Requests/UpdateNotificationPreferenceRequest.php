<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryEnum = ['in:financial,security,operational,marketing'];

        return [
            'push_enabled_categories'   => ['nullable', 'array'],
            'push_enabled_categories.*' => $categoryEnum,
            'sms_enabled_categories'    => ['nullable', 'array'],
            'sms_enabled_categories.*'  => $categoryEnum,
            'email_enabled_categories'  => ['nullable', 'array'],
            'email_enabled_categories.*' => $categoryEnum,
            'marketing_opted_in'        => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach (['push_enabled_categories', 'sms_enabled_categories', 'email_enabled_categories'] as $field) {
                $value = $this->input($field);

                if (empty($value)) {
                    continue;
                }

                if (! in_array('financial', $value, true) || ! in_array('security', $value, true)) {
                    $v->errors()->add(
                        $field,
                        'MANDATORY_CATEGORY_REQUIRED: financial and security must always be included.'
                    );
                }
            }
        });
    }
}
