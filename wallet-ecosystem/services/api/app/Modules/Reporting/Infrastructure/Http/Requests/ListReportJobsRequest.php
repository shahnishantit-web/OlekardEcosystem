<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Requests;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use App\Modules\Reporting\Domain\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;

final class ListReportJobsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status'      => ['nullable', 'string', 'in:'.implode(',', array_column(ReportJobStatus::cases(), 'value'))],
            'report_type' => ['nullable', 'string', 'in:'.implode(',', array_column(ReportType::cases(), 'value'))],
            'page'        => ['nullable', 'integer', 'min:1'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
