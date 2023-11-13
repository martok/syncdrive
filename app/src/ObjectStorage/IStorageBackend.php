<?php

namespace App\ObjectStorage;

use Nepf2\Application;

interface IStorageBackend
{
    public function __construct(Application $app, array $config);

    /**
     * Get a new reader for the object data
     *
     * @param string $object
     * @return resource|null Stream resource opened for reading
     */
    public function openReader(string $object): mixed;

    /**
     * Get a new writer for as yet unknown object name
     *
     * @return IObjectWriter Writer object
     */
    public function openTempWriter(): IObjectWriter;

    /**
     * Remove a temporary writer's files. Mostly used when the file is empty.
     * Called alternatively to makePermanent.
     *
     * @param IObjectWriter $writer The writer previously opened
     */
    public function removeTemporary(IObjectWriter $writer): void;

    /**
     * Transfer the object to permanent storage.
     *
     * @param ObjectInfo $info Metadata with the final object name
     * @param IObjectWriter $writer The writer previously opened
     */
    public function makePermanent(ObjectInfo $info, IObjectWriter $writer): void;

    /**
     * Remove an object.
     *
     * @param string $object
     * @return bool True if the object was removed successfully
     */
    public function removeObject(string $object): bool;
}