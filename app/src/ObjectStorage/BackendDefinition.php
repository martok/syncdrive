<?php

namespace App\ObjectStorage;

readonly class BackendDefinition
{
    public function __construct(
        public int             $intent,
        public IStorageBackend $backend,
    )
    {
    }

    public function isIntent(int $intent): bool
    {
        return 0 !== ($this->intent & $intent);
    }
}