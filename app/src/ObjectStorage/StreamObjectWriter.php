<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage;

use App\Exception;
use App\Streams\CustomStream;
use App\Streams\StreamProtocol;

class StreamObjectWriter extends StreamProtocol
{
    private IObjectWriter $writer;
    private int $chunkSize;

    protected array $hashes = [];

    private ?CustomStream $stream;
    protected int $totalSize = 0;
    private ?int $chunkIndex = null;
    private int $chunkUsed = 0;

    public function __construct(IObjectWriter $writer, int $chunkSize, array $hashAlgos)
    {
        $this->writer = $writer;
        $this->chunkSize = $chunkSize;
        foreach ($hashAlgos as $alg) {
            try {
                $this->hashes[$alg] = hash_init($alg, 0, '');
            } catch (\ValueError) {
                throw new Exception("Invalid hash function: $alg");
            }
        }
        $this->stream = CustomStream::OpenWrapped('w', $this);
    }

    public function getHash(?string $alg = null): ?string
    {
        if (!count($this->hashes))
            return null;
        $alg ??= array_key_first($this->hashes);
        $val = $this->hashes[$alg] ?? null;
        if (is_string($val))
            return $val;
        return null;
    }

    public function hasStream(): bool
    {
        return !is_null($this->stream);
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
        foreach (array_keys($this->hashes) as $alg)
            $this->hashes[$alg] = hash_final($this->hashes[$alg], false);
        $this->writer->close();
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
        foreach ($this->hashes as $hash)
            hash_update($hash, $data);
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