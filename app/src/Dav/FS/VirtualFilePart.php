<?php

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
        return sprintf('%06d', $this->part->part);
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