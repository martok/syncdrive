<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\ObjectStorage\B2Backend;

use Carbon\Carbon;
use Psr\Log\LoggerInterface;

class LoggingClient extends \BackblazeB2\Client
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
}
