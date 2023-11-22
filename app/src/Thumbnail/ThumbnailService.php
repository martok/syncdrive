<?php

namespace App\Thumbnail;

use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\FS\Node;
use App\Model\Thumbnails;
use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;

class ThumbnailService
{
    public function __construct(
        private Context $context
    )
    {
    }

    /**
     * Called when the object storage permanently removes an object. At that point,
     * thumbnails of that object can be garbage-collected.
     *
     * @param ObjectStorage $storage
     * @param string $object
     * @return void
     */
    public static function RemoveObjectThumbnails(ObjectStorage $storage, string $object): bool
    {
        foreach (Thumbnails::findBy(['for_object' => $object]) as $thumb) {
            if (!$storage->safeRemoveObject($thumb->object))
                return false;
            $thumb->delete();
        }
        return true;
    }

    private static function Thumbnailers(): array
    {
        // use a static variable to avoid loading the classes unless we really need them
        static $list = [
            ImagineThumbnailer::class,
        ];
        return $list;
    }

    public function maybeCreateThumbnail(ObjectInfo $object, string $fileName): bool
    {
        $cfg = $this->context->app->cfg('thumbnails');
        $enabled = $cfg['enabled'];
        $maxSize = ini_parse_quantity($cfg['maxFileSize']);
        $resolutions = $cfg['resolutions'];

        if (!$enabled || !count($resolutions))
            return false;
        // don't attempt thumbnails on too large or empty files
        if ($object->object == ObjectStorage::EMPTY_OBJECT ||
            $object->size > $maxSize)
            return false;
        // check what thumbnailers can maybe handle this file type
        $candidates = [];
        foreach (self::Thumbnailers() as $thumbnailerClass) {
            if (call_user_func([$thumbnailerClass, 'Supports'], $fileName, $object->size)) {
                $candidates[] = $thumbnailerClass;
            }
        }
        // run the thumbnailers until we have all resolutions
        $result = false;
        $trx = false;
        foreach ($candidates as $candidate) {
            $obj = new $candidate($this->context->storage, $object, $fileName);
            assert($obj instanceof IThumbnailer);
            $remainingResolutions = [];
            while ([$wi, $he] = array_shift($resolutions)) {
                if (!$trx) {
                    Thumbnails::db()->beginTransaction();
                    $trx = true;
                }
                $contentType = '';
                if ($thumbObject = $obj->produce($wi, $he, $contentType)) {
                    $tn = new Thumbnails([
                        'for_object' => $object->object,
                        'width' => $wi,
                        'height' => $he,
                        'content_type' => $contentType,
                        'object' => $thumbObject->object
                    ]);
                    $tn->save();
                    Thumbnails::db()->commit();
                    $trx = false;
                    $result = true;
                } else {
                    $remainingResolutions[] = [$wi, $he];
                }
            }
            // if any are remaining, continue with the next candidate
            $resolutions = $remainingResolutions;
            if (!count($resolutions))
                break;
        }
        return $result;
    }

    public function findThumbnail(Node $file, int $width, int $height): ?Thumbnails
    {
        assert($file instanceof File);

        $ver = $file->getCurrentVersion();
        // find the largest thumbnail smaller or equal than requested
        $tn = Thumbnails::findOne(['for_object' => $ver->object,
                                   'width<=' => $width, 'height<=' => $height],
                                  ['order' => ['width DESC', 'height DESC']]);
        if (is_null($tn->id)) {
            // if this did not work, try the smallest one (larger than requested)
            $tn = Thumbnails::findOne(['for_object' => $ver->object],
                                      ['order' => ['width ASC', 'height ASC']]);
            // no thumbnail at all
            if (is_null($tn->id))
                return null;
        }

        return $tn;
    }


}