<?php

namespace App\ObjectStorage\B2Backend;

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
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    protected function sendAuthorizedRequest($method, $route, $json = [])
    {
        $this->logger->info(sprintf('Request: %s %s %s', $method, $route, json_encode($json)));
        return parent::sendAuthorizedRequest($method, $route, $json); // TODO: Change the autogenerated stub
    }
}
