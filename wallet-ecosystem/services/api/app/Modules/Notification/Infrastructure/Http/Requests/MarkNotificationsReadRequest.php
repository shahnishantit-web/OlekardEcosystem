<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'      => ['nullable', 'array', 'max:100'],
            'ids.*'    => ['uuid'],
            'mark_all' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasIds     = ! empty($this->input('ids'));
            $markAll    = (bool) $this->input('mark_all', false);

            if (! $hasIds && ! $markAll) {
                $v->errors()->add('ids', 'Either ids or mark_all=true must be provided.');
            }

            if ($hasIds && $markAll) {
                $v->errors()->add('ids', 'Provide either ids or mark_all, not both.');
            }
        });
    }
}
