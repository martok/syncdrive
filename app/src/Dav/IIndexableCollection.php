<?php

namespace App\Dav;

/**
 * Interface for the extended functionality provided by Directory.
 *
 * This is also implemented by VirtualRoot and should be used in instanceof
 * queries only in those cases where a potential virtual file system root
 * folder is expected - the root generated for shared individual files.
 *
 * Most of the time, use Directory directly instead.
 *
 */
interface IIndexableCollection extends \Sabre\DAV\ICollection
{
    public function hasChildren(): bool;
    public function getChildrenWithDeleted(): array;
}