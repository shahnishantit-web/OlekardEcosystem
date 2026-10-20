<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format'    => ['sometimes', 'string', 'in:csv,json'],
            'type'      => ['sometimes', 'string', 'in:funding,transfer,withdrawal,refund'],
            'status'    => ['sometimes', 'string', 'in:pending,completed,failed,reversed,all'],
            'wallet_id' => ['sometimes', 'uuid'],
            'from'      => ['sometimes', 'date'],
            'to'        => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }

    public function exportFormat(): string
    {
        return $this->validated()['format'] ?? 'csv';
    }
}
