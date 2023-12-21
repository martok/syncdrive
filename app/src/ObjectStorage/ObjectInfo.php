<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

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

    public function withName(string $object): static {
        return new static(
            $object,
            $this->size,
            $this->hash,
            $this->chunkSize,
            $this->checksums
        );
    }
}