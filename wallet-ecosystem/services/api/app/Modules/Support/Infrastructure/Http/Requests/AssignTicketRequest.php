<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'uuid'],
        ];
    }
}
