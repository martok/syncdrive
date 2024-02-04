<?php

namespace App\ObjectStorage\B2Backend;

use App\ObjectStorage\IObjectWriter;
use App\ObjectStorage\IStorageBackend;
use App\Streams\CustomStream;
use BackblazeB2\Client;
use BackblazeB2\Exceptions\B2Exception;
use BackblazeB2\File;
use Nepf2\Application;
use Nepf2\Util\Arr;
use Nepf2\Util\Path;
use Nepf2\Util\Random;

class B2Backend implements IStorageBackend
{
    private \Psr\Log\LoggerInterface $logger;
    private Client $client;
    private string $bucketName;
    private string $bucketId;
    private int $cacheMaxSize;
    private string $cachePath;

    public function __construct(Application $app, array $config)
    {
        $config = Arr::ExtendConfig([
            'keyId' => '',
            'applicationKey' => '',
            'bucketName' => '',
            'bucketId' => '',
            'cache' => [
                'maxSize' => '0',
                'path' => 'data/b2cache',
            ]
        ], $config);
        $this->logger = $app->getLogChannel('B2');
        $this->client = new LoggingClient($this->logger, $config['keyId'], $config['applicationKey']);
        $this->bucketName = $config['bucketName'];
        $this->bucketId = $config['bucketId'];
        $this->cacheMaxSize = ini_parse_quantity($config['cache']['maxSize']);
        $this->cachePath = $app->expandPath($config['cache']['path']);
    }

    /**
     * @inheritDoc
     */
    public function objectExists(string $object): bool
    {
        try {
            return $this->client->fileExists([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $this->getFileName($object, 0)
            ]);
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed fileExists', ['exception' => $ex]);
            throw $ex;
        }
    }

    /**
     * @inheritDoc
     */
    public function openReader(string $object): mixed
    {
        $reader = new B2Reader($this, $object);
        $stream = CustomStream::OpenWrapped('r', $reader);
        return is_null($stream) ? null : $stream->getResource();
    }

    /**
     * @inheritDoc
     */
    public function openWriter(string $object): IObjectWriter
    {
        return new B2Writer($this, $object);
    }

    /**
     * @inheritDoc
     */
    public function removeObject(string $object): bool
    {
        $parts = $this->doGetFileParts($object);
        if (!count($parts))
            return false;

        foreach ($parts as $part) {
            $this->doDeleteFile($part);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function moveObject(string $source, string $dest): bool
    {
        $movedAny = false;
        $sparts = $this->doGetFileParts($source);
        $dnames = [];
        foreach ($this->doGetFileParts($dest) as $dpart) {
            $dnames[$dpart->getName()] = true;
        }
        for ($i=0; $i<count($sparts);$i++) {
            $spart = $sparts[$i];
            $dname = $this->getFileName($dest, $i);

            if (!isset($dnames[$dname])) {
                // the target doesn't exist, create it
                $this->doCopy($spart, $dname);
            }
            // remove the source, either because we moved it or because the target already existed
            // (where we assume it is the same file)
            $this->doDeleteFile($spart);
            $movedAny = true;
        }

        return $movedAny;
    }

    public function getFileName(string $object, int $chunkIndex): string
    {
        // B2 works with paths only, "folders" are only parsed from file identifiers on specific calls
        // like b2_list_file_names, which we use in the reader implementation for random access. To be able to do that,
        // we need a name pattern that works well and doesn't return expensive extra results.
        // In this case simply: $objectid/$chunkid
        $chunkExt = str_pad(dechex($chunkIndex), 3, '0', STR_PAD_LEFT);
        return $object . '/' . $chunkExt;
    }

    public function doUpload(string $fileName, string $data)
    {
        try {
            return $this->client->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $fileName,
                'Body' => $data,
                'FileContentType' => 'application/octet-stream'
            ]);
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed doUpload', ['exception' => $ex]);
            throw $ex;
        }
    }

    /**
     * @param string $object
     * @return File[]
     */
    public function doGetFileParts(string $object): array
    {
        try {
            return $this->client->listFiles([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'Prefix' => $object . '/',
                'Delimiter' => '/',
            ]);
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed doGetFileParts', ['exception' => $ex]);
            throw $ex;
        }
    }

    private function cacheLookup(File $file, ?string &$data): bool
    {
        if (!$this->cacheMaxSize)
            return false;
        $key = sprintf('%s_%08x', $file->getHash(), $file->getUploadTimestamp() & 0xffff_ffff);
        $cfile = Path::ExpandRelative($this->cachePath, $key);
        if (file_exists($cfile) &&
            ($fp = fopen($cfile, "rb"))) {
            flock($fp, LOCK_SH);
            touch($cfile);
            $data = stream_get_contents($fp);
            fclose($fp);
            return true;
        }
        return false;
    }

    private function cacheExpire(int $spaceRequired): void
    {
        if (!$this->cacheMaxSize)
            return;

        if (!is_dir($this->cachePath))
            return;
        $files = [];
        $total = 0;
        foreach (scandir($this->cachePath, SCANDIR_SORT_NONE) as $file) {
            $file = Path::ExpandRelative($this->cachePath, $file);
            if (is_file($file)) {
                $total += filesize($file);
                $files[] = $file;
            }
        }

        if ($this->cacheMaxSize - $total >= $spaceRequired)
            return;

        // sort most recently touched to top
        usort($files, function(string $a, string $b) {
            $t1 = filemtime($a) || 0;
            $t2 = filemtime($b) || 0;
            return $t2 - $t1;
        });

        // remove from bottom until enough space is created
        while (($this->cacheMaxSize - $total < $spaceRequired) && count($files)) {
            $remove = array_pop($files);
            $total -= filesize($remove);
            unlink($remove);
        }
    }

    private function cacheStore(File $file, string &$data): bool
    {
        if (!$this->cacheMaxSize)
            return false;
        $key = sprintf('%s_%08x', $file->getHash(), $file->getUploadTimestamp() & 0xffff_ffff);
        $cfile = Path::ExpandRelative($this->cachePath, $key);
        if (file_exists($cfile))
            return true;

        $this->cacheExpire($file->getSize());

        if (!is_dir($this->cachePath))
            mkdir($this->cachePath, recursive: true);
        $newf = $cfile . '.' . Random::TokenStr();
        file_put_contents($newf, $data);
        if (!rename($newf, $cfile))
            unlink($newf);
        return true;
    }

    public function doDownload(File $file, bool $skipCache = false): string
    {
        if (!$skipCache && ($this->cacheLookup($file, $data)))
            return $data;
        try {
            $data = $this->client->download([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileId' => $file->getId()
            ]);
            $this->cacheStore($file, $data);
            return $data;
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed doDownload', ['exception' => $ex]);
            throw $ex;
        }
    }

    private function doDeleteFile(File $file): bool
    {
        try {
            return $this->client->deleteFile([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $file->getName(),
                'FileId' => $file->getId(),
            ]);
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed deleteFile', ['exception' => $ex]);
            throw $ex;
        }
    }

    private function doCopy(File $file, string $newName): File
    {
        try {
            return $this->client->copy([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'metadataDirective' => 'COPY',
                'sourceFileId' => $file->getId(),
                'fileName' => $newName,
            ]);
        } catch (B2Exception $ex) {
            $this->logger->error('B2 failed copy', ['exception' => $ex]);
            throw $ex;
        }
    }
}