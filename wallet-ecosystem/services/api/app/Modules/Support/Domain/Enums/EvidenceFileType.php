<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum EvidenceFileType: string
{
    case ImageJpeg = 'image_jpeg';
    case ImagePng  = 'image_png';
    case ImageWebp = 'image_webp';
    case Pdf       = 'pdf';

    public static function fromMimeType(string $mime): self
    {
        return match ($mime) {
            'image/jpeg' => self::ImageJpeg,
            'image/png'  => self::ImagePng,
            'image/webp' => self::ImageWebp,
            'application/pdf' => self::Pdf,
            default => throw new \ValueError("Unsupported MIME type: {$mime}"),
        };
    }
}
