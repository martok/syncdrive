<?php

namespace App\Browser;

use App\Controller\DavController;
use App\Controller\TreeUtil;
use App\Dav\Context;
use Nepf2\Util\Path;

class BrowserMain extends BrowserViewBase
{
    public const VIEW_STYLES = ['table', 'tiled'];

    public function getDefaultIndexState(): array
    {
        return array_merge_recursive(parent::getDefaultIndexState(), [
            'view' => self::VIEW_STYLES[0],
        ]);
    }

    public function emitDirectoryIndex(Context $context, array $state): void
    {
        $listing = $this->getListing(showDeleted: $state['showDeleted']);
        $listing = TreeUtil::filterListKeys($listing, [
            'id', 'path', 'name', 'modified', 'size', 'perms', 'icon', 'deleted', 'isShared', 'isFolder', 'ownerName'
        ]);
        $uriFile = $this->nodeToListing($this->requestedItem, $this->uriItem);

        $view = $this->controller->initTemplateView('index_browse.twig');
        $view->set('files', [
            'breadcrumbs' => TreeUtil::getPathBreadcrumbs(explode('/', $this->uriItem)),
            'base' => $this->uriBase,
            'list' => $listing,
            'view' => $state['view'],
            'showing_deleted' => $state['showDeleted'],
        ]);
        $view->export('BROWSE_PATH', Path::IncludeTrailingSlash('/' . $this->uriItem));
        $view->export('URI_BASE', $this->uriBase);
        $view->export('CURRENT_PERMISSIONS', (string)$this->requestedItem->getInnerPerms());
        $view->export('CURRENT_FILE', $uriFile);
        $view->export('BROWSE_UPLOAD_PATH', DavController::MakeUserPath($context->identity->getUserId(),
                                                                        Path::IncludeTrailingSlash($this->uriItem)));
        $view->export('SHARE_PUBLIC_PRESENTATIONS', PresentationBase::AvailablePresentations());
        $this->response->setBody($view->render());
    }
}