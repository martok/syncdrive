<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

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
    const IMAGE_CACHE = 365*24*3600;

    #[Auto\Route('/index.php/core/preview', method: 'GET')]
    public function preview(Response $res, Request $req)
    {
        try {
            $fileId = $req->int('fileId');
            $dimX = $req->int('x');
            $dimY = $req->int('y');
        } catch (\TypeError) {
            $res->standardResponse(400);
            return;
        }

        // access to a file by id can be given by:
        //  - app login (file manager thumbnail)
        //  - session login (browser)
        //  - share login, anonymous share use (share)

        $identity = new Identity($this->app->cfg('site.title'));
        if ($token = $req->str('share')) {
            // if the client tells us we're looking at a share, test *only* that.
            $share = InodeShares::findOne(['token' => $token]);
            if (is_null($share->id)) {
                $res->standardResponse(404);
                return;
            }
            if (!$identity->initShare($this->session, $share, null, false)) {
                $res->standardResponse(403);
                return;
            }
        } else {
            if (!$identity->initSession($this->session) && !$identity->initApp($req, $res)) {
                $res->standardResponse(403);
                return;
            }
        }

        $context = new Context($this->app, $identity);
        if (!$context->canAccessInode($fileId)) {
            $res->standardResponse(403);
            return;
        }

        $file = Node::FromInode(Inodes::Find($fileId), $context);

        // set headers now, we want missing thumbs to be cached as well
        $res->setHeader('Cache-Control', 'private, max-age='.self::IMAGE_CACHE.', immutable');
        $res->setHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + self::IMAGE_CACHE));

        $thumbs = new ThumbnailService($context);
        if (is_null($thumb = $thumbs->findThumbnail($file, $dimX, $dimY))) {
            // normally, we'd just return 404 (and do that for app logins, ie. sync clients)
            // browsers however don't cache 404 responses or empty responses, when the request was for an <img>.
            // so instead, we have to lie and say we found a 1x1 transparent gif to avoid future hits.
            if (is_null($identity->appLogin)) {
                $res->setHeader('Content-Type', 'image/gif');
                $res->setBody(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
            } else {
                $res->standardResponse(404);
            }
            return;
        }

        $context->setupStorage();
        $res->setHeader('Content-Type', $thumb->content_type);
        $res->setBody($context->storage->openReader($thumb->object));
    }
}