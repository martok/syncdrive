<?php

namespace App\Streams;

class CustomStream extends StreamProtocol
{
    public const PROTOCOL = 'stream-custom';

    public static function OpenWrapped(string $mode, StreamProtocol $delegate): ?CustomStream
    {
        $fh = fopen(self::PROTOCOL . '://', $mode);
        /** @var CustomStream $inst */
        $inst = stream_get_meta_data($fh)['wrapper_data'];
        $inst->setDelegate($delegate);
        $inst->setResource($fh);
        if (!$inst->stream_open('',$mode, 0, $p)) {
            fclose($fh);
            return null;
        }
        return $inst;
    }

    private ?StreamProtocol $delegate = null;
    private mixed $resource;

    public function getDelegate(): StreamProtocol
    {
        return $this->delegate;
    }

    public function setDelegate(StreamProtocol $delegate): void
    {
        $this->delegate = $delegate;
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @param resource $resource
     */
    protected function setResource($resource): void
    {
        $this->resource = $resource;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (is_null($this->delegate))
            return true;
        return $this->delegate->stream_open($path, $mode, $options, $opened_path);
    }

    public function stream_close(): void
    {
        $this->delegate->stream_close();
    }

    public function stream_eof(): bool
    {
        if (is_null($this->delegate))
            return false;
        return $this->delegate->stream_eof();
    }

    public function stream_flush(): bool
    {
        return $this->delegate->stream_flush();
    }

    public function stream_lock(int $operation): bool
    {
        return $this->delegate->stream_lock($operation);
    }

    public function stream_read(int $count): string|false
    {
        $md = stream_get_meta_data($this->resource);
        return $this->delegate->stream_read($count);
    }

    public function stream_write(string $data): int
    {
        return $this->delegate->stream_write($data);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->delegate->stream_seek($offset, $whence);
    }

    public function stream_tell(): int
    {
        return $this->delegate->stream_tell();
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return $this->delegate->stream_set_option($option, $arg1, $arg2);
    }

    public function stream_stat(): array|false
    {
        return $this->delegate->stream_stat();
    }

    public function stream_truncate(int $new_size): bool
    {
        return $this->delegate->stream_truncate($new_size);
    }
}


stream_wrapper_register(CustomStream::PROTOCOL, CustomStream::class) or die('Failed to register protocol '.CustomStream::PROTOCOL.'://');