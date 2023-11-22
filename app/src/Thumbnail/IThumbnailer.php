<?php

namespace App\Thumbnail;

use App\ObjectStorage\ObjectInfo;
use App\ObjectStorage\ObjectStorage;

interface IThumbnailer
{
    public function __construct(ObjectStorage $storage, ObjectInfo $sourceObject, string $sourceFilename);

    public static function Supports(string $fileName): bool;

    public function produce(int $width, int $height, string &$contentType): ?ObjectInfo;
}