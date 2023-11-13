<?php

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
}