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

class B2Backend implements IStorageBackend
{
    private \Psr\Log\LoggerInterface $logger;
    private Client $client;
    private string $bucketName;
    private string $bucketId;

    public function __construct(Application $app, array $config)
    {
        $config = Arr::ExtendConfig([
            'keyId' => '',
            'applicationKey' => '',
            'bucketName' => '',
            'bucketId' => '',
        ], $config);
        $this->logger = $app->getLogChannel('B2');
        $this->client = new LoggingClient($this->logger, $config['keyId'], $config['applicationKey']);
        $this->bucketName = $config['bucketName'];
        $this->bucketId = $config['bucketId'];
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

    public function doDownload(File $file): string
    {
        try {
            return $this->client->download([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileId' => $file->getId()
            ]);
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