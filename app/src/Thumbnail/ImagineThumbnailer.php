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
use Elephox\Mimey\MimeType;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Imagick\Imagine;
use Nepf2\Util\Path;
use Nepf2\Util\Random;
use Psr\Log\LoggerInterface;

class ImagineThumbnailer implements IThumbnailer
{
    private const SAVE_EXT = '.jpg';
    private const SAVE_CONTENT_TYPE = MimeType::ImageJpeg->value;
    private const SAVE_OPTIONS = ['jpeg_quality' => '50'];

    private ImageInterface|bool|null $image = null;

    public static function Supports(string $fileName, int $fileSize): bool
    {
        // Imagick supports most image formats - so we just guess if it is one
        if (!($mime = ThumbnailService::MimeTypeFromFile($fileName)))
            return false;
        if (str_starts_with($mime->value, 'image/') ||
            in_array($mime, [
                MimeType::ApplicationPdf,
            ])) {
            return true;
        }
        return false;
    }

    public function __construct(
        private ObjectStorage $storage,
        private ObjectInfo $sourceObject,
        private string $sourceFilename,
        private LoggerInterface $logger)
    {
    }

    private function tryLoad()
    {
        try {
            $reader = $this->storage->openReader($this->sourceObject->object);
            $imagine = new Imagine();
            $this->image = $imagine->read($reader);
        } catch (\Throwable $ex) {
            $this->logger->warning('Failed to load image for ' . $this->sourceFilename, ['exception' => $ex]);
            $this->image = false;
        }
    }

    private function imageSaveToObject(ImageInterface $image): ?ObjectInfo
    {
        $tempfile = Path::Join(sys_get_temp_dir(), Random::TokenStr() . self::SAVE_EXT);
        try {
            $image->save($tempfile, self::SAVE_OPTIONS);
            $file = fopen($tempfile, 'rb');
            try {
                return $this->storage->storeNewObject($file);
            } finally {
                fclose($file);
            }
        } finally {
            @unlink($tempfile);
        }
    }

    public function produce(int $width, int $height, string &$contentType): ?ObjectInfo
    {
        // load image on first attempt
        if (is_null($this->image))
            $this->tryLoad();
        if ($this->image === false)
            return null;

        $tnBox = new Box($width, $height);
        $tn = $this->image->thumbnail($tnBox);
        $contentType = self::SAVE_CONTENT_TYPE;
        return $this->imageSaveToObject($tn);
    }
}