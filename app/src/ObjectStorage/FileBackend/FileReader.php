<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage\FileBackend;

use App\Streams\StreamProtocol;

class FileReader extends StreamProtocol
{
    private FileBackend $backend;
    private readonly string $object;
    private int $chunkIndex = 0;
    private int $pos = 0;
    private int $startOfChunk = 0;
    private mixed $file = null;
    private bool $eof = false;

    public function __construct(FileBackend $backend, string $object)
    {
        $this->object = $object;
        $this->backend = $backend;
    }

    private function rewind(): bool
    {
        $this->pos = 0;
        $this->eof = false;
        return $this->chunkBegin(0);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return $this->rewind();
    }

    public function stream_close(): void
    {
        if (!is_null($this->file))
            fclose($this->file);
        $this->eof = true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->eof)
            return '';
        $readBlocks = [];
        $readbytes = 0;
        while ($readbytes < $count && !$this->eof) {
            $part = fread($this->file, $count - $readbytes);
            $readBlocks[] = $part;
            $readbytes += strlen($part);
            if (feof($this->file)) {
                if (!$this->chunkBegin($this->chunkIndex + 1))
                    break;
            }
        }
        $this->pos += $readbytes;
        return join('', $readBlocks);
    }

    public function stream_eof(): bool
    {
        return $this->eof;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        switch ($whence) {
            case SEEK_CUR: $offset += $this->pos; break;
            case SEEK_END: return false;
        }
        // start from beginning and skip ahead until we find the chunk the target offset is in
        $this->rewind();
        while (!$this->eof) {
            $chunkSize = fstat($this->file)['size'];
            $chunkOffset = $offset - $this->startOfChunk;
            if ($chunkOffset >= 0 && $chunkOffset < $chunkSize) {
                // found the correct chunk, seek it
                fseek($this->file, $chunkOffset, SEEK_SET);
                $this->pos = $offset;
                return true;
            }
            $this->pos += $chunkSize;
            $this->chunkBegin($this->chunkIndex + 1);
        }
        return false;
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    private function chunkBegin(int $newIndex): bool
    {
        if (!is_null($this->file))
            fclose($this->file);
        $name = $this->backend->getFileName($this->object, $newIndex);
        if (is_file($name) &&
            ($fh = fopen($name, 'rb'))) {
            $this->chunkIndex = $newIndex;
            $this->startOfChunk = $this->pos;
            $this->file = $fh;
            return true;
        }
        $this->eof = true;
        $this->file = null;
        return false;
    }
}