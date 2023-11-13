<?php

namespace App\Dav;

class Perm
{
    const CAN_WRITE = 1 << 0;            // W
    const CAN_DELETE = 1 << 1;           // D
    const CAN_RENAME = 1 << 2;           // N
    const CAN_MOVE = 1 << 3;             // V
    const CAN_ADDFILE = 1 << 4;          // C
    const CAN_MKDIR = 1 << 5;            // K
    const CAN_RESHARE = 1 << 6;          // R
    const IS_SHARED = 1 << 7;            // S
    const IS_MOUNTED = 1 << 8;           // M
    const IS_MOUNTED_SUB = 1 << 9;       // m

    const DEFAULT_OWNED    = 0b0_0011_1111;
    const DEFAULT_READONLY = 0b0_0000_0000;

    private const PERMISSION_CHARS = 'WDNVCKRSMm';
    public const PERMISSION_MASK = (self::IS_MOUNTED_SUB << 1) - 1;
    public const INHERITABLE_MASK = (self::CAN_MKDIR << 1) - 1;
    public const LINK_OUTER_MASK = self::CAN_RESHARE;
    public const FLAG_MASK = self::PERMISSION_MASK & ~self::INHERITABLE_MASK;

    public static function FromString(string $permissions): int
    {
        $mask = 0;
        for ($c = 0; $c < strlen($permissions); $c++) {
            if (false !== ($i = strpos(self::PERMISSION_CHARS, $permissions[$c]))) {
                $mask |= 1 << $i;
            }
        }
        return $mask;
    }

    public static function ToString(int $permissions): string
    {
        $str = '';
        for ($i = 0; $i < strlen(self::PERMISSION_CHARS); $i ++) {
            if ($permissions & (1 << $i))
                $str .= self::PERMISSION_CHARS[$i];
        }
        return $str;
    }
}