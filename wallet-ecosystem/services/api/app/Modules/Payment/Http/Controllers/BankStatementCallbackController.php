<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Services\ProviderStatementService;
use App\Modules\Payment\Domain\Exceptions\StatementAlreadyExistsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class BankStatementCallbackController extends Controller
{
    public function __construct(
        private readonly ProviderStatementService $service,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider_code'  => ['required', 'string', 'max:50'],
            'account_id'     => ['required', 'string', 'max:100'],
            'environment'    => ['required', 'string', 'in:production,sandbox'],
            'currency_code'  => ['required', 'string', 'size:3'],
            'period_start'   => ['required', 'date'],
            'period_end'     => ['required', 'date', 'after_or_equal:period_start'],
            'lines'          => ['required', 'array', 'min:1'],
            'lines.*.line_type'             => ['required', 'string', 'in:capture,fee,refund,reversal,chargeback,internal_transfer,adjustment'],
            'lines.*.provider_reference_id' => ['required', 'string', 'max:200'],
            'lines.*.amount_minor'          => ['required', 'integer', 'min:0'],
            'lines.*.currency_code'         => ['required', 'string', 'size:3'],
            'lines.*.direction'             => ['required', 'string', 'in:credit,debit'],
            'lines.*.settled_at'            => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $statement = $this->service->ingest($request->all(), null);

            return response()->json([
                'data' => [
                    'statement_id' => $statement->id,
                    'status'       => $statement->status->value,
                    'source_hash'  => $statement->source_hash,
                    'total_lines'  => $statement->total_lines,
                ],
            ], 202);
        } catch (StatementAlreadyExistsException $e) {
            return response()->json([
                'message'              => $e->getMessage(),
                'existing_statement_id' => $e->existingStatementId,
            ], 409);
        }
    }
}
