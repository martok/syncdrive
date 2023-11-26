<?php

namespace App\Browser;

use App\Controller\DavController;
use App\Controller\TreeUtil;
use App\Dav\Context;
use App\Dav\FS\File;
use Nepf2\Request;
use Nepf2\Util\Path;

class PresentationRich extends PresentationBase
{
    public function getDefaultIndexState(): array
    {
        return array_merge_recursive(parent::getDefaultIndexState(), [
            'view' => BrowserMain::VIEW_STYLES[0],
            'share' => '',
        ]);
    }

    public function updateIndexState(Request $req, array &$state): void
    {
        if (in_array(($opt = $req->str('view')), BrowserMain::VIEW_STYLES))
            $state['view'] = $opt;
    }

    public function emitDirectoryIndex(Context $context, array $state): void
    {
        $listing = $this->getListing(showDeleted: $state['showDeleted']);
        $listing = TreeUtil::filterListKeys($listing, [
            'id', 'path', 'name', 'modified', 'size', 'perms', 'icon', 'deleted', 'isShared', 'isFolder', 'ownerName'
        ]);

        $view = $this->controller->initTemplateView('public/pres_rich.twig');
        $view->set('files', [
            'breadcrumbs' => TreeUtil::getPathBreadcrumbs(explode('/', $this->uriItem)),
            'base' => $this->uriBase,
            'list' => $listing,
            'view' => $state['view'],
        ]);
        $view->export('BROWSE_PATH', Path::IncludeTrailingSlash('/' . $this->uriItem));
        $view->export('URI_BASE', $this->uriBase);
        $view->export('CURRENT_PERMISSIONS', (string)$this->requestedItem->getInnerPerms());
        $view->export('SHARE_TOKEN', $state['share']);
        $view->export('BROWSE_UPLOAD_PATH', DavController::MakeSharePath(Path::IncludeTrailingSlash($this->uriItem)));
        $this->response->setBody($view->render());
    }

    public function serveFileDirect(Context $context): void
    {
        // default to raw for audio/video/image
        if ($this->requestedItem instanceof File) {
            $mime = $this->requestedItem->guessContentType();
            if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/'))
                $context->app->request()->setQueryParam('raw', '1');
        }
        parent::serveFileDirect($context);
    }
}