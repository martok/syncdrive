<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage\B2Backend;

use BackblazeB2\Exceptions\B2Exception;
use BackblazeB2\File;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Iterator;
use Psr\Log\LoggerInterface;

class ExtendedClient extends \BackblazeB2\Client
{
    private LoggerInterface $logger;

    /**
     * @inheritDoc
     */
    public function __construct(LoggerInterface $logger, $accountId, $applicationKey, array $options = [])
    {
        parent::__construct($accountId, $applicationKey, $options);
        $this->loadAuthInfo();
        $this->logger = $logger;
    }

    private function getAuthCacheKey(): string
    {
        // sha1 to prevent leaking details via cache key names
        return sha1(join('_', [self::class, $this->accountId, $this->applicationKey]));
    }

    private function loadAuthInfo(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled())
            return;
        if (($obj = apcu_fetch($this->getAuthCacheKey(), $success)) && $success && is_array($obj)) {
            $this->authToken = $obj['authToken'];
            $this->apiUrl = $obj['apiUrl'];
            $this->downloadUrl = $obj['downloadUrl'];
            $this->reAuthTime = $obj['reAuthTime'];
        }
    }

    private function saveAuthInfo(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled())
            return;
        $obj = [
            'authToken' => $this->authToken,
            'apiUrl' => $this->apiUrl,
            'downloadUrl' => $this->downloadUrl,
            'reAuthTime' => $this->reAuthTime,
        ];
        apcu_store($this->getAuthCacheKey(), $obj, $this->authTimeoutSeconds * 2);
    }

    /**
     * @inheritDoc
     */
    protected function authorizeAccount(): void
    {
        if (Carbon::now('UTC')->timestamp >= $this->reAuthTime->timestamp) {
            parent::authorizeAccount();
            $this->saveAuthInfo();
        }
    }

    /**
     * @inheritDoc
     */
    protected function sendAuthorizedRequest($method, $route, $json = [])
    {
        $this->logger->info(sprintf('Request: %s %s %s', $method, $route, json_encode($json)));
        return parent::sendAuthorizedRequest($method, $route, $json);
    }

    /**
     * Retrieve an iterator for all File objects in a bucket. Doesn't support most options of `listFiles`.
     *
     * @param array $options
     *
     * @throws GuzzleException If the request fails.
     * @throws B2Exception     If the B2 server replies with an error.
     *
     * @return Iterator<int, File>
     */
    public function listFilesIterator(array $options): Iterator
    {
        $nextFileName = null;
        $maxFileCount = 10000;

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        $this->authorizeAccount();

        // B2 returns, at most, 1000 files per "page". Loop through the pages and compile an array of File objects.
        do {
            $response = $this->sendAuthorizedRequest('POST', 'b2_list_file_names', [
                'bucketId'      => $options['BucketId'],
                'startFileName' => $nextFileName,
                'maxFileCount'  => $maxFileCount,
                'prefix'        => '',
                'delimiter'     => null,
            ]);

            foreach ($response['files'] as $file) {
                $entry = new File($file['fileId'], $file['fileName'], $file['contentSha1'], $file['size'], $file['contentType'], $file['fileInfo'], $file['bucketId'], $file['action'], $file['uploadTimestamp']);
                yield $entry;
            }

            $nextFileName = $response['nextFileName'];
        } while ($nextFileName);
    }
}
