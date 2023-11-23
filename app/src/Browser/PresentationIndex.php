<?php

namespace App\Browser;

use App\Controller\TreeUtil;
use App\Dav\Context;

class PresentationIndex extends PresentationBase
{
    public function emitDirectoryIndex(Context $context, array $state): void
    {
        $sortedByKey = match ($context->app->request()->str('s')) {
            'n' => 'name',
            'm' => 'modified',
            's' => 'size',
            'N' => '-name',
            'M' => '-modified',
            'S' => '-size',
            default => 'name'
        };
        $listing = $this->getListing(showDeleted: false, sortedByKey: $sortedByKey);
        $listing = TreeUtil::filterListKeys($listing, [
            'path', 'name', 'modified', 'size', 'contentType', 'perms'
        ]);
        if ($this->uriItem) {
            array_unshift($listing, [
                'path' => $this->uriItem . '/..',
                'name' => '..',
                'modified' => $this->requestedItem->getLastModified(),
            ]);
        }

        $view = $this->controller->initTemplateView('public/pres_index.twig');
        $view->set('title', 'Index of /' . $this->uriItem);
        $view->set('files', [
            'base' => $this->uriBase,
            'basePermissions' => (string)$this->requestedItem->getInnerPerms(),
            'sorting' => $context->app->request()->str('s'),
            'list' => $listing,
        ]);
        $this->response->setBody($view->render());
    }

    public function serveFileDirect(Context $context): void
    {
        // default to raw, unless requested otherwise
        $context->app->request()->setQueryParam('raw', '1');
        parent::serveFileDirect($context);
    }


}