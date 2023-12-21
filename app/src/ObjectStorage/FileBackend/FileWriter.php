<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage\FileBackend;

use App\ObjectStorage\IObjectWriter;
use App\ObjectStorage\IStorageBackend;

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

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if (!is_null($this->currChunk)) {
            fclose($this->currChunk);
        }
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
}