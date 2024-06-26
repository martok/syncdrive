<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Thumbnail;

use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;
use Psr\Log\LoggerInterface;

class DirectThumbnailer implements IThumbnailer
{
    /**
     * Mime-Types that will be handled regardless of file size. Used if it is more likely to be supported
     * by browsers than by Imagick
     */
    private const BROWSER_FORCED_MIME = [
        'image/x-icon',
    ];
    /**
     * Maximum file size for direct use. Weigh between storage efficiency and transfer size
     */
    private const MAX_FILE_SIZE = 2*1024;
    /**
     * Mime-Types that will be handled if the file is smaller than self::MAX_FILE_SIZE
     */
    private const BROWSER_SUPPORTED_MIME = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/x-ms-bmp',
        // 'image/tiff',        only supported by Safari
    ];

    public static function Supports(string $fileName, int $fileSize): bool
    {
        // if the file is small and likely supported by browsers themselves,
        // assert this class can "produce" a thumbnail

        if (!($mime = ThumbnailService::MimeTypeFromFile($fileName)))
            return false;

        if (in_array($mime->value, self::BROWSER_FORCED_MIME, true))
            return true;

        if ($fileSize > self::MAX_FILE_SIZE)
            return false;

        return in_array($mime->value, self::BROWSER_SUPPORTED_MIME, true);
    }

    public function __construct(
        private ObjectStorage $storage,
        private ObjectInfo $sourceObject,
        private string $sourceFilename,
        private LoggerInterface $logger)
    {
    }

    public function produce(int $width, int $height, string &$contentType): ?ObjectInfo
    {
        if (!($mime = ThumbnailService::MimeTypeFromFile($this->sourceFilename)))
            return null;

        // Return the original object as thumbnail, ignoring the size requests. An image that fits in MAX_FILE_SIZE
        // is likely not larger than the requested thumbnail size anyway.
        $contentType = $mime->value;
        return $this->sourceObject;
    }
}