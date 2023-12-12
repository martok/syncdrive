<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav;

readonly class PermSet
{
    private int $bitmask;

    public function __toString(): string
    {
        return Perm::ToString($this->bitmask);
    }

    public function __construct(int|string $mask)
    {
        if (is_string($mask))
            $mask = Perm::FromString($mask);
        $this->bitmask = $mask;
    }

    public function has(int|string $permission): bool
    {
        if (is_string($permission))
            $permission = Perm::FromString($permission);
        return ($this->bitmask & $permission) === $permission;
    }

    public function value(): int
    {
        return $this->bitmask;
    }

    public function with(int $flags): static
    {
        return new static($this->bitmask | $flags & Perm::PERMISSION_MASK);
    }

    public function without(int $flags): static
    {
        return new static(($this->bitmask & ~$flags) & Perm::PERMISSION_MASK);
    }

    public function inherit(int $parent): static
    {
        $p = ($this->bitmask & $parent) & Perm::INHERITABLE_MASK;
        return new static($p | ($this->bitmask & Perm::FLAG_MASK));
    }
}