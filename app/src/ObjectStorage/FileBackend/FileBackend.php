<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage\FileBackend;

use App\ObjectStorage\IObjectWriter;
use App\ObjectStorage\IStorageBackend;
use App\ObjectStorage\ObjectInfo;
use App\Streams\CustomStream;
use Generator;
use Iterator;
use Nepf2\Application;
use Nepf2\Util\Arr;
use Nepf2\Util\Path;

class FileBackend implements IStorageBackend
{

    private string $path;

    public function __construct(Application $app, array $config)
    {
        $config = Arr::ExtendConfig([
            'path' => 'data/blob',
        ], $config);
        $this->path = $app->expandPath($config['path']);
    }

    /**
     * @inheritDoc
     */
    public function objectExists(string $object): bool
    {
        return is_file($this->getFileName($object, 0));
    }

    /**
     * @inheritDoc
     */
    public function openReader(string $object): mixed
    {
        $reader = new FileReader($this, $object);
        $stream = CustomStream::OpenWrapped('r', $reader);
        return is_null($stream) ? null : $stream->getResource();
    }

    /**
     * @inheritDoc
     */
    public function openWriter(string $object): IObjectWriter
    {
        return new FileWriter($this, $object);
    }

    /**
     * @inheritDoc
     */
    public function removeObject(string $object): bool
    {
        $files = [];
        for ($i=0;;$i++) {
            $sname = $this->getFileName($object, $i);
            if (is_file($sname)) {
                $files[] = $sname;
            } else {
                break;
            }
        }

        if (!count($files))
            return false;

        foreach ($files as $file)
            unlink($file);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function moveObject(string $source, string $dest): bool
    {
        $movedAny = false;
        for ($i=0;;$i++) {
            $sname = $this->getFileName($source, $i);
            if (is_file($sname)) {
                $dname = $this->getFileName($dest, $i);
                // if the file already exists, assume it is the same and just discard the new one
                if (is_file($dname))
                    unlink($sname);
                else {
                    $this->makeParentDirs($dname);
                    rename($sname, $dname);
                }
                $movedAny = true;
            } else {
                break;
            }
        }
        return $movedAny;
    }

    /**
     * @inheritDoc
     */
    public function estimateCapacity(int &$used, int &$available): bool
    {
        $available = disk_free_space($this->path);
        $used = 0;
        foreach ($this->storedObjectsIterator() as $obj) {
            $used += $obj->size;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function storedObjectsIterator(): Iterator
    {
        $currObj = '';
        $currSize = 0;
        foreach (scandir($this->path, SCANDIR_SORT_NONE) as $prefix) {
            if (str_starts_with($prefix, '.'))
                continue;
            $prefixPath = Path::Join($this->path, $prefix);
            if (!is_dir($prefixPath))
                continue;
            // need the chunks in ascending order to correctly figure out when the next object starts
            foreach (scandir($prefixPath, SCANDIR_SORT_ASCENDING) as $chunk) {
                if (str_starts_with($chunk, '.'))
                    continue;
                $pi = pathinfo($chunk);
                $objName = $prefix . $pi['filename'];
                $chunkNo = $pi['extension'];

                $fullChunkName = Path::Join($prefixPath, $chunk);
                if (!($stat = @stat($fullChunkName)))
                    continue;

                // new file name -> everything collected for current object
                if ($objName !== $currObj) {
                    if ($currObj) {
                        yield new ObjectInfo($currObj, $currSize, '', 0, []);
                    }
                    $currObj = $objName;
                    $currSize = 0;
                }
                $currSize += $stat['size'];
            }
        }
        if ($currObj) {
            yield new ObjectInfo($currObj, $currSize, '', 0, []);
        }
    }

    public function getFileName(string $object, int $chunkIndex): string
    {
        $chunkExt = str_pad(dechex($chunkIndex), 3, '0', STR_PAD_LEFT);
        $pathFile = substr($object, 0, 3) . DIRECTORY_SEPARATOR . substr($object, 3);
        // subdir by 3 hex chars = 4096 directories; also aligns nicely with tmp_ from getTemporaryName
        return Path::ExpandRelative($this->path, $pathFile . '.' . $chunkExt);
    }

    public function makeParentDirs(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir))
            mkdir($dir, recursive: true);
    }
}