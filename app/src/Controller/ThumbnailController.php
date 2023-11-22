<?php

namespace App\Controller;

use App\Dav\Backend\ServerAdapter;
use App\Dav\Context;
use App\Dav\FS\Node;
use App\Dav\Identity;
use App\Dav\NodeResolver;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Thumbnail\ThumbnailService;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;
use Sabre\DAV;
use Sabre\HTTP\Auth;

class ThumbnailController extends Base
{
    #[Auto\Route('/index.php/core/preview', method: 'GET')]
    public function userdav(Response $res, Request $req)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initSession($this->session) && !$identity->initApp($req, $res)) {
            $identity->sendChallenge($req, $res);
            return;
        }

        $context = new Context($this->app, $identity);

        try {
            $fileId = $req->int('fileId');
            $dimX = $req->int('x');
            $dimY = $req->int('y');
        } catch (\TypeError) {
            $res->standardResponse(400);
            return;
        }

        if (!NodeResolver::InodeVisibleIn($fileId, $context->getFilesRootInode()->id)) {
            $res->standardResponse(403);
            return;
        }

        $file = Node::FromInode(Inodes::Find($fileId), $context);

        $thumbs = new ThumbnailService($context);

        if (is_null($thumb = $thumbs->findThumbnail($file, $dimX, $dimY))) {
            $res->standardResponse(404);
            return;
        }

        $context->setupStorage();
        $res->setHeader('Content-Type', $thumb->content_type);
        $res->setBody($context->storage->openReader($thumb->object));
    }
}