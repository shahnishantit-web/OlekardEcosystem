<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AttachEvidenceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'file'  => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,mp4,mov'],
            'label' => ['nullable', 'string', 'max:200'],
        ];
    }
}
