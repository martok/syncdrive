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

    public function intentToStr(): string
    {
        $inv = array_flip(ObjectStorage::BACKEND_INTENTS);
        $intents = [];
        foreach ($inv as $i => $name) {
            if ($this->isIntent($i))
                $intents[] = $name;
        }
        return join(', ', $intents);
    }
}