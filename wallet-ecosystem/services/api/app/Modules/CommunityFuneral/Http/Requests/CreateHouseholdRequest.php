<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'private_reference' => ['required', 'string', 'max:200'],
        ];
    }
}
