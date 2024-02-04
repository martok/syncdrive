<?php

namespace App\ObjectStorage\B2Backend;

use App\ObjectStorage\IObjectWriter;
use App\ObjectStorage\IStorageBackend;

class B2Writer implements IObjectWriter
{
    private B2Backend $backend;
    public readonly string $name;
    private int $chunkIndex = -1;
    private string $chunkData = '';

    public function __construct(B2Backend $backend, string $name)
    {
        $this->name = $name;
        $this->backend = $backend;
    }

    /**
     * @inheritDoc
     */
    public function beginChunk(int $nextIndex): void
    {
        if ($this->chunkIndex >= 0)
            $this->uploadAndClearChunk();
        $this->chunkIndex = $nextIndex;
    }

    /**
     * @inheritDoc
     */
    public function write(string $data): int
    {
        $this->chunkData .= $data;
        return strlen($data);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->chunkIndex >= 0)
            $this->uploadAndClearChunk();
    }

    /**
     * @inheritDoc
     */
    public function getObject(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getBackend(): IStorageBackend
    {
        return $this->backend;
    }

    private function uploadAndClearChunk()
    {
        $chunkFile = $this->backend->getFileName($this->name, $this->chunkIndex);
        $this->backend->doUpload($chunkFile, $this->chunkData);
        $this->chunkData = '';
    }


}