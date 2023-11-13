<?php

namespace App\Browser;

use App\Controller\DavController;
use App\Controller\TreeUtil;
use App\Dav\Context;
use Nepf2\Util\Path;

class BrowserMain extends BrowserViewBase
{
    public function emitDirectoryIndex(Context $context, bool $includeDeleted): void
    {
        $listing = $this->getListing(showDeleted: $includeDeleted);
        $listing = TreeUtil::filterListKeys($listing, [
            'path', 'name', 'modified', 'size', 'perms', 'icon', 'deleted', 'isShared', 'isFolder', 'ownerName'
        ]);
        $view = $this->controller->initTemplateView('index_browse.twig');
        $view->set('files', [
            'breadcrumbs' => TreeUtil::getPathBreadcrumbs(explode('/', $this->uriItem)),
            'base' => $this->uriBase,
            'list' => $listing,
            'showing_deleted' => $includeDeleted,
        ]);
        $view->export('BROWSE_PATH', Path::IncludeTrailingSlash('/' . $this->uriItem));
        $view->export('URI_BASE', $this->uriBase);
        $view->export('CURRENT_PERMISSIONS', (string)$this->requestedItem->getInnerPerms());
        $view->export('BROWSE_UPLOAD_PATH', DavController::MakeUserPath($context->identity->getUserId(),
                                                                        Path::IncludeTrailingSlash($this->uriItem)));
        $view->export('SHARE_PUBLIC_PRESENTATIONS', PresentationBase::AvailablePresentations());
        $this->response->setBody($view->render());
    }
}