<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage;

use Iterator;
use Nepf2\Application;

interface IStorageBackend
{
    public function __construct(Application $app, array $config);

    /**
     * Check for object existence
     *
     * @param string $object
     * @return bool
     */
    public function objectExists(string $object): bool;

    /**
     * Get a new reader for the object data
     *
     * @param string $object
     * @return resource|null Stream resource opened for reading
     */
    public function openReader(string $object): mixed;

    /**
     * Get a new writer with the given object name
     *
     * @param string $object
     * @return IObjectWriter Writer object
     */
    public function openWriter(string $object): IObjectWriter;

    /**
     * Remove an object.
     *
     * @param string $object
     * @return bool True if the object was removed successfully
     */
    public function removeObject(string $object): bool;

    /**
     * Move/rename an object.
     *
     * @param string $source Old name
     * @param string $dest New name
     * @return bool True if the object was moved successfully
     */
    public function moveObject(string $source, string $dest): bool;

    /**
     * Get storage statistics estimation. May both over- or underestimate the true value.
     *
     * @param int $used total space currently used, or -1 if unknown
     * @param int $available space available, or -1 if unknown
     * @return bool True on success
     */
    public function estimateCapacity(int &$used, int &$available): bool;

    /**
     * Iterator (or Generator) of all objects stored by this backend.
     * The returned ObjectInfo objects may be arbitrarily incomplete, as a general rule,
     * only trust the object name and size.
     *
     * @return Iterator<int, ObjectInfo>
     */
    public function storedObjectsIterator(): Iterator;
}