<?php

namespace App\ObjectStorage\B2Backend;

use App\ObjectStorage\FileBackend\FileBackend;
use App\Streams\StreamProtocol;

class B2Reader extends StreamProtocol
{
    private B2Backend $backend;
    private readonly string $object;
    private int $chunkIndex = 0;
    private int $pos = 0;
    private int $startOfChunk = 0;
    private ?string $currentChunk = null;
    private bool $eof = false;
    /** @var \BackblazeB2\File[] */
    private array $parts;

    public function __construct(B2Backend $backend, string $object)
    {
        $this->object = $object;
        $this->backend = $backend;
        $this->parts = $backend->doGetFileParts($object);
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
        if (!is_null($this->currentChunk))
            $this->currentChunk = null;
        $this->eof = true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->eof)
            return '';
        $readBlocks = [];
        $readbytes = 0;
        while ($readbytes < $count && !$this->eof) {
            $posInChunk = $this->pos - $this->startOfChunk;
            $part = substr($this->currentChunk, $posInChunk, $count - $readbytes);
            $partLen = strlen($part);
            $readBlocks[] = $part;
            $readbytes += $partLen;
            $this->pos += $partLen;
            if ($posInChunk + $partLen >= strlen($this->currentChunk)) {
                if (!$this->chunkBegin($this->chunkIndex + 1))
                    break;
            }
        }
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
            case SEEK_END:
            {
                $totalSize = 0;
                foreach ($this->parts as $file) {
                    $totalSize .= $file->getSize();
                }
                $offset = $totalSize - $offset;
            }
        }
        $chunkStart = 0;
        $chunkIdx = 0;
        foreach ($this->parts as $file) {
            $chunkEnd = $chunkStart + $file->getSize();
            if ($offset >= $chunkStart && $offset < $chunkEnd) {
                $this->chunkBegin($chunkIdx);
                $this->pos = $offset;
                return true;
            }
            $chunkIdx++;
            $chunkStart = $chunkEnd;
        }
        return false;
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    private function chunkBegin(int $newIndex): bool
    {
        if (!is_null($this->currentChunk))
            $this->currentChunk = null;

        if (($newIndex < count($this->parts)) &&
            ($file = $this->parts[$newIndex]) &&
            ($fd = $this->backend->doDownload($file))) {
            $this->chunkIndex = $newIndex;
            $this->startOfChunk = $this->pos;
            $this->currentChunk = $fd;
            return true;
        }
        $this->eof = true;
        $this->currentChunk = null;
        return false;
    }
}