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
}