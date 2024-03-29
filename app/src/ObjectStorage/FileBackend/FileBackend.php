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
use App\Streams\CustomStream;
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