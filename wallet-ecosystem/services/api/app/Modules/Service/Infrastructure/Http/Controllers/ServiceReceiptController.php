<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Controllers;

use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Infrastructure\Http\Resources\ServiceReceiptResource;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ServiceReceiptController extends Controller
{
    public function __construct(private readonly ServiceReceiptService $receiptService) {}

    public function show(Request $request, string $txId): ServiceReceiptResource
    {
        $requestorId = (string) $request->attributes->get('identity.user_id');

        return new ServiceReceiptResource(
            $this->receiptService->getReceipt($txId, $requestorId)
        );
    }
}
