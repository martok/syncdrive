<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav\FS;

use App\Model\ChunkedUploadParts;

class VirtualFilePart extends \Sabre\DAV\File
{
    public function __construct(
        private ChunkedUploadParts $part
    )
    {
    }

    public function getName()
    {
        return $this->part->part;
    }

    public function get()
    {
        return '';
    }

    public function getSize()
    {
        return $this->part->size;
    }

    public function getETag()
    {
        return '"'.sha1($this->part->object).'"';
    }
}