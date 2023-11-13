<?php

namespace App\Streams;

class Stream
{
    public static function IsStream(mixed $value): bool
    {
        return is_resource($value) && 'stream' == get_resource_type($value);
    }

    public static function CopyToStream(mixed $data, mixed $stream, int $bufLen = 4 * 1024 * 1024): int
    {
        assert(self::IsStream($stream));
        if (self::IsStream($data)) {
            $totalCopied = 0;
            // see comments in \Sabre\HTTP\Sapi::sendResponse
            stream_set_chunk_size($stream, $bufLen);
            do {
                $copied = stream_copy_to_stream($data, $stream, $bufLen);
                $totalCopied += $copied;
                // Abort on client disconnect.
                if (1 === ignore_user_abort() && 1 === connection_aborted()) {
                    break;
                }
            } while ($copied > 0);
            return $totalCopied;
        } elseif (is_string($data)) {
            return fwrite($stream, $data);
        } else {
            throw new \RuntimeException('Unexpected data type.');
        }
    }
}