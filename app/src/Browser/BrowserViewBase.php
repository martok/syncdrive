<?php

namespace App\Browser;

use App\Controller\Base;
use App\Controller\TreeUtil;
use App\Dav\Backend\ServerAdapter;
use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\FS\Node;
use App\Dav\IIndexableCollection;
use App\Dav\NodeResolver;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Model\Users;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Tree;

class BrowserViewBase
{
    protected ?Tree $tree;
    protected Node|IIndexableCollection|null $requestedItem;
    protected ?string $uriBase;
    protected ?string $uriItem;
    protected ?ServerAdapter $server;

    public function __construct(
        protected Base $controller,
        protected Request $request,
        protected Response $response,
    )
    {
    }

    public function initRequestedItem(Context $context, array $path): Node|IIndexableCollection|null
    {
        $this->uriBase = rtrim(TreeUtil::requestBaseUri($this->request, $path), '/');
        $this->uriItem = Path::Canonicalize(join('/', $path));

        $this->tree = $context->getFilesView();
        try {
            $item = $this->tree->getNodeForPath($this->uriItem);
            assert($item instanceof Node || $item instanceof IIndexableCollection);
            return $this->requestedItem = $item;
        } catch (NotFound $e) {
            return null;
        }
    }

    public function initServer(): ServerAdapter
    {
        $this->server = new ServerAdapter($this->tree, $this->request, $this->response);
        TreeUtil::setupServer($this->server, $this->uriBase);
        return $this->server;
    }

    public function initRequestedVersion(int $version, int $timestamp): bool
    {
        assert($this->requestedItem instanceof File);
        // if a specific revision was requested, tune the (already cached!) File
        // object so it points there
        if (!($version = $this->requestedItem->getVersion($version, $timestamp)))
            return false;
        $this->requestedItem->setBoundVersion($version);
        return true;
    }

    protected function nodeToListing(Node $node, string $qualifiedPath): array
    {
        $size = $node instanceof File ? $node->getSize() : 0;
        $hasChildren = $node instanceof IIndexableCollection ? $node->hasChildren() : false;
        return [
            '.' => $node,
            'id' => $node->getInodeId(),
            'name' => $node->getName(),
            'path' => $qualifiedPath,
            'isFolder' => $node instanceof IIndexableCollection,
            'contentType' => $node instanceof File ? $node->getContentType() ?? '' : null,
            'icon' => TreeUtil::getNodeIcon($node),
            'modified' => $node->getLastModified(),
            'size' => $size,
            'hasChildren' => $hasChildren,
            'isShared' => $node->isLink() || $node->hasShares(),
            'deleted' => $node->deletedTimestamp(),
            'ownerName' => NodeResolver::UserGetName($node->getInode(false)->owner_id),
            'perms' => (string)$node->getPerms(),
        ];
    }

    public function getListing(bool $showDeleted=false, string $sortedByKey='name'): array
    {
        assert($this->requestedItem instanceof IIndexableCollection);
        $list = [];
        $children = $showDeleted ? $this->requestedItem->getChildrenWithDeleted() : $this->requestedItem->getChildren();
        foreach ($children as $file) {
            $qualifiedName = $file->isDeleted() ? $file->getQualifiedName() : $file->getName();
            $qualifiedPath = TreeUtil::extendPath($this->uriItem, $qualifiedName);
            $list[] = $this->nodeToListing($file, $qualifiedPath);
        }
        if (str_starts_with($sortedByKey, '-')) {
            $invert = -1;
            $sortedByKey = substr($sortedByKey, 1);
        } else {
            $invert = 1;
        }
        usort($list, fn ($left, $right) => -($left['isFolder'] <=> $right['isFolder']) ?: ($invert * ($left[$sortedByKey] <=> $right[$sortedByKey])));
        return $list;
    }

    public function emitShareLogin(InodeShares $share): void
    {
        $owner = Users::findOne(['id' => $share->sharer_id]);
        $node = Inodes::Find($share->inode_id);

        $view = $this->controller->initTemplateView('public/share_login.twig');
        $view->set('share', [
            'token' => $share->token,
            'name' => $node->name ?? $share->token,
            'sharer' => $owner->username ?? 'Unknown user',
        ]);
        $this->response->setBody($view->render());
    }

    public function emitNotFound(): void {}

    public function serveFileDirect(Context $context): void
    {
        assert($this->requestedItem instanceof File);
        // for complete handling of range, if-modified etc., just let Sabre do it
        // but ensure the correct file name is presented regardless of inode specifiers
        $context->setupStorage();
        if ($context->forceDownloadResponse())
            $this->response->setHeader('Content-Disposition', sprintf('attachment; filename="%s"',
                                            str_replace('"', '%22', $this->requestedItem->getName())));
        // tell the client we can do range-requests
        $this->response->setHeader('Accept-Ranges', 'bytes');
        $this->server->start();
    }

    public function emitDirectoryIndex(Context $context, bool $includeDeleted): void {}
}