<?php

namespace App\ObjectStorage;

use App\Streams\CustomStream;
use App\Streams\StreamProtocol;

class StreamObjectWriter extends StreamProtocol
{
    public const HASH_ALG_DEFAULT = 'sha256';

    private IObjectWriter $writer;
    private int $chunkSize;

    protected \HashContext $hash;
    protected ?string $hashValue = null;

    private ?CustomStream $stream;
    protected int $totalSize = 0;
    private ?int $chunkIndex = null;
    private int $chunkUsed = 0;

    public function __construct(IObjectWriter $writer, int $chunkSize, string $hashAlgo = self::HASH_ALG_DEFAULT)
    {
        $this->writer = $writer;
        $this->chunkSize = $chunkSize;
        $this->hash = hash_init($hashAlgo, 0, '');
        $this->stream = CustomStream::OpenWrapped('w', $this);
    }

    public function getHash(): ?string
    {
        return $this->hashValue;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->stream->getResource();
    }

    public function stream_close(): void
    {
        $this->hashValue = hash_final($this->hash, false);
        $this->stream = null;
    }

    public function stream_write(string $data): int
    {
        $remain = strlen($data);
        $start = 0;
        while ($remain > 0) {
            // maybe start new chunk
            $toWrite = $this->prepareChunk($remain);
            if (!$toWrite)
                break;
            // write a block to the currently active chunk
            $part = substr($data, $start, $toWrite);
            $written = $this->writer->write($part);
            // accounting
            $remain -= $written;
            $start += $written;
            $this->chunkUsed += $written;
        }
        hash_update($this->hash, $data);
        $this->totalSize += $start;
        return $start;
    }

    public function stream_tell(): int
    {
        return $this->totalSize;
    }

    /**
     * Ensure the currently active chunk can accept up to $remaining bytes.
     * May switch to a new chunk if the current one is full.
     *
     * @param int $remaining Number of bytes that should be written
     * @return int Number of bytes that can be written
     */
    private function prepareChunk(int $remaining): int
    {
        $space = $this->chunkSize - $this->chunkUsed;
        if ($space <= 0 || is_null($this->chunkIndex)) {
            $nextIndex = ($this->chunkIndex ?? -1) + 1;
            $this->writer->beginChunk($nextIndex);
            $this->chunkIndex = $nextIndex;
            $this->chunkUsed = 0;
            $space = $this->chunkSize - $this->chunkUsed;
        }
        return min($space, $remaining);
    }
}