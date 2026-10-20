<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Profile\Application\Commands\UploadProfilePhotoCommand;
use App\Modules\Profile\Domain\Exceptions\FileTooLargeException;
use App\Modules\Profile\Domain\Exceptions\ImageDimensionsTooSmallException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Exceptions\UnsupportedMimeTypeException;
use App\Modules\Profile\Domain\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProfilePhotoController extends Controller
{
    public function __construct(
        private readonly ProfilePhotoService $photoService,
    ) {}

    /**
     * POST /v1/users/profile/photo
     * Accepts multipart/form-data with a `photo` field.
     * Returns signed URL only — raw storage key is never exposed.
     */
    public function store(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        $file = $request->file('photo');

        if ($file === null || ! $file->isValid()) {
            return $this->errorResponse('VALIDATION_ERROR', 'A valid photo file is required.', 400, $correlationId);
        }

        $command = new UploadProfilePhotoCommand(
            userId:        $userId,
            actorId:       $userId,
            actorType:     $actorType,
            correlationId: $correlationId,
            file:          $file,
        );

        try {
            $signedUrl = $this->photoService->upload($command);

            return response()->json([
                'success' => true,
                'data'    => ['profile_photo_url' => $signedUrl],
            ], 200, ['X-Correlation-ID' => $correlationId]);

        } catch (UnsupportedMimeTypeException) {
            return $this->errorResponse('UNSUPPORTED_MEDIA_TYPE', 'The uploaded file type is not accepted.', 415, $correlationId);
        } catch (FileTooLargeException) {
            return $this->errorResponse('FILE_TOO_LARGE', 'The uploaded file exceeds the maximum allowed size.', 413, $correlationId);
        } catch (ImageDimensionsTooSmallException) {
            return $this->errorResponse('IMAGE_DIMENSIONS_TOO_SMALL', 'The image dimensions are below the required minimum.', 422, $correlationId);
        } catch (ProfileNotFoundException) {
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        }
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
