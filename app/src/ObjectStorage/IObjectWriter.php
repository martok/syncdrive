<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage;

interface IObjectWriter
{
    /**
     * Start writing the next chunk
     *
     * @param int $nextIndex
     * @return void
     */
    public function beginChunk(int $nextIndex): void;

    /**
     * Write data to the currently active chunk
     *
     * @param string $data
     * @return int Number of bytes written
     */
    public function write(string $data): int;

    /**
     * Close the writer
     *
     * @return void
     */
    public function close(): void;

    /**
     * Return the object name currently being written
     *
     * @return string
     */
    public function getObject(): string;

    /**
     * Return the owning storage backend instance
     *
     * @return IStorageBackend
     */
    public function getBackend(): IStorageBackend;
}