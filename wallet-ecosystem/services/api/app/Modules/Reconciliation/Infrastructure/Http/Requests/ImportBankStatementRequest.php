<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportBankStatementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file'            => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'batch_date'      => ['required', 'date_format:Y-m-d'],
            'statement_id'    => ['nullable', 'string', 'max:256'],
        ];
    }
}
