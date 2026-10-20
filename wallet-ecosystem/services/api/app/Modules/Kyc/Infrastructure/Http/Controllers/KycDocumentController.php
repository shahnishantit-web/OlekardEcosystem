<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Http\Controllers;

use App\Modules\Kyc\Application\Services\KycDocumentService;
use App\Modules\Kyc\Domain\Enums\KycDocumentType;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class KycDocumentController extends Controller
{
    public function __construct(private readonly KycDocumentService $service) {}

    public function upload(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        $request->validate([
            'document_type' => 'required|in:national_id,passport,drivers_licence,utility_bill,selfie,other',
            'file'          => 'required|file',
            'expires_on'    => 'nullable|string',
        ]);

        $type      = KycDocumentType::from($request->input('document_type'));
        $file      = $request->file('file');
        $expiresOn = $request->input('expires_on');

        // Return 400 immediately for validation failures on MIME or size
        $formatCheck = $this->service->validateFormat($file);
        if (!$formatCheck['valid']) {
            return $this->errorResponse($formatCheck['error'], 'Document validation failed.', 400, $correlationId);
        }

        // Validate expiry field format if provided
        if ($expiresOn !== null) {
            $expiryCheck = $this->service->validateExpiry($expiresOn);
            if (!$expiryCheck['valid']) {
                $status = $expiryCheck['error'] === 'INVALID_EXPIRY_DATE' ? 400 : 422;
                return $this->errorResponse($expiryCheck['error'], 'Document expiry validation failed.', $status, $correlationId);
            }
        }

        try {
            $doc = $this->service->upload(
                caseId:        $id,
                actorId:       $userId,
                type:          $type,
                file:          $file,
                expiresOn:     $expiresOn,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'document_id'       => $doc->id,
                    'document_type'     => $doc->document_type->value,
                    'validation_status' => $doc->validation_status,
                    'mime_type'         => $doc->mime_type,
                    'file_size_bytes'   => $doc->file_size_bytes,
                    'expires_on'        => $doc->expires_on?->format('Y-m-d'),
                ],
                'error' => null,
                'meta'  => null,
            ], 201, ['X-Correlation-ID' => $correlationId]);

        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function validateFormat(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $request->validate(['file' => 'required|file']);

        $result = $this->service->validateFormat($request->file('file'));

        return response()->json([
            'success' => true,
            'data'    => $result,
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function validateExpiry(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $request->validate(['expires_on' => 'nullable|string']);

        $expiresOn = $request->input('expires_on');

        // If the string is clearly not a date format, return HTTP 400
        if ($expiresOn !== null) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $expiresOn);
            if ($parsed === false || $parsed->format('Y-m-d') !== $expiresOn) {
                return $this->errorResponse('INVALID_EXPIRY_DATE', 'expires_on must be a valid date in Y-m-d format.', 400, $correlationId);
            }
        }

        $result = $this->service->validateExpiry($expiresOn);

        return response()->json([
            'success' => true,
            'data'    => $result,
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
