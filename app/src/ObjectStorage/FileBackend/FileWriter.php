<?php

namespace App\ObjectStorage\FileBackend;

use App\ObjectStorage\IObjectWriter;

class FileWriter implements IObjectWriter
{
    private FileBackend $backend;
    public readonly string $name;
    private mixed $currChunk = null;

    public function __construct(FileBackend $backend, string $name)
    {
        $this->name = $name;
        $this->backend = $backend;
    }

    /**
     * @inheritDoc
     */
    public function beginChunk(int $nextIndex): void
    {
        if (!is_null($this->currChunk)) {
            fclose($this->currChunk);
        }
        $name = $this->backend->getFileName($this->name, $nextIndex);
        $this->backend->makeParentDirs($name);
        $this->currChunk = fopen($name, 'cb');
    }

    /**
     * @inheritDoc
     */
    public function write(string $data): int
    {
        return fwrite($this->currChunk, $data, strlen($data));
    }
}