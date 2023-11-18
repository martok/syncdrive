<?php

namespace App\Dav;

use App\Exception;

class TransferChecksums
{
    public static function OC2php(string|array $OC): string|array
    {
        if (is_string($OC)) {
            return match ($OC) {
                'SHA3-256' => 'sha3-256',
                'SHA256' => 'sha256',
                'SHA1' => 'sha1',
                'MD5' => 'md5',
                default => throw new Exception("Invalid OC checksum algorithm specifed: $OC")
            };
        }
        return array_map(self::OC2php(...), $OC);
    }

    public static function SplitHeader(string $header, ?string &$alg=null, ?string &$hash=null): bool
    {
        if (2 != count($s = explode(':', $header, 2)))
            return false;
        $alg = $s[0];
        $hash = $s[1];
        return true;
    }

    public static function Serialize(array $checksums): string
    {
        $result = '';
        foreach ($checksums as $alg => $value) {
            $result .= sprintf("%s:%s\n", $alg, $value);
        }
        return $result;
    }

    public static function Unserialize(string $hashes): ?array
    {
        $lines = explode("\n", $hashes);
        // the last line is either empty (good data) or truncated before the linebreak (truncated field)
        array_pop($lines);

        $result = [];
        foreach ($lines as $line) {
            if (2 !== count($kv = explode(':', $line)))
                continue;
            $result[$kv[0]] = $kv[1];
        }
        if (count($result))
            return $result;
        return null;
    }

    public static function FormatDownloadHeader(array $hashes, array $preferredOrder): string
    {
        // return the first preferred hash, or the first of the array if not found, or the empty string if no hash is given
        foreach([...$preferredOrder, array_key_first($hashes)] as $alg) {
            if (isset($hashes[$alg]))
                return sprintf('%s:%s', $alg, $hashes[$alg]);
        }
        return '';
    }
}