<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav\Backend;

use Sabre\DAV\Exception;
use Sabre\DAV\Server;
use Sabre\DAV\Version;
use Sabre\HTTP;

class ServerAdapter extends Server
{
    public function __construct($treeOrNode, HTTP\RequestInterface $request, HTTP\ResponseInterface $response)
    {
        parent::__construct($treeOrNode, null);
        $this->setRequestIO($request, $response);
    }

    public function setRequestIO(HTTP\RequestInterface $request, HTTP\ResponseInterface $response)
    {
        $this->httpRequest = $request;
        $this->httpResponse = $response;
    }

    public function sendException(\Throwable|Exception $exception)
    {
        $DOM = new \DOMDocument('1.0', 'utf-8');
        $DOM->formatOutput = true;

        $error = $DOM->createElementNS('DAV:', 'd:error');
        $error->setAttribute('xmlns:s', self::NS_SABREDAV);
        $DOM->appendChild($error);

        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_NOQUOTES, 'UTF-8');
        };

        if (self::$exposeVersion) {
            $error->appendChild($DOM->createElement('s:sabredav-version', $h(Version::VERSION)));
        }

        $error->appendChild($DOM->createElement('s:exception', $h(get_class($exception))));
        $error->appendChild($DOM->createElement('s:message', $h($exception->getMessage())));
        if ($this->debugExceptions) {
            $error->appendChild($DOM->createElement('s:file', $h($exception->getFile())));
            $error->appendChild($DOM->createElement('s:line', $h($exception->getLine())));
            $error->appendChild($DOM->createElement('s:code', $h($exception->getCode())));
            $error->appendChild($DOM->createElement('s:stacktrace', $h($exception->getTraceAsString())));
        }

        if ($this->debugExceptions) {
            $previous = $exception;
            while ($previous = $previous->getPrevious()) {
                $xPrevious = $DOM->createElement('s:previous-exception');
                $xPrevious->appendChild($DOM->createElement('s:exception', $h(get_class($previous))));
                $xPrevious->appendChild($DOM->createElement('s:message', $h($previous->getMessage())));
                $xPrevious->appendChild($DOM->createElement('s:file', $h($previous->getFile())));
                $xPrevious->appendChild($DOM->createElement('s:line', $h($previous->getLine())));
                $xPrevious->appendChild($DOM->createElement('s:code', $h($previous->getCode())));
                $xPrevious->appendChild($DOM->createElement('s:stacktrace', $h($previous->getTraceAsString())));
                $error->appendChild($xPrevious);
            }
        }

        if ($exception instanceof Exception) {
            $httpCode = $exception->getHTTPCode();
            $exception->serialize($this, $error);
            $headers = $exception->getHTTPHeaders($this);
        } else {
            $httpCode = 500;
            $headers = [];
        }
        $headers['Content-Type'] = 'application/xml; charset=utf-8';

        $this->httpResponse->setStatus($httpCode);
        $this->httpResponse->setHeaders($headers);
        $this->httpResponse->setBody($DOM->saveXML());
        $this->sapi->sendResponse($this->httpResponse);
    }
}