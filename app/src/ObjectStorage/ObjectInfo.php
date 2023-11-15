<?php

namespace App\ObjectStorage;

readonly class ObjectInfo
{
    public function __construct(
        public string $object,
        public int $size,
        public string $hash,
        public int $chunkSize,
        public array $checksums
    )
    {
    }
}