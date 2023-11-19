<?php

namespace App\Controller;

use App\Dav\Backend\ServerAdapter;
use App\Dav\Context;
use App\Dav\Identity;
use App\Model\InodeShares;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;
use Sabre\DAV;
use Sabre\HTTP\Auth;

class DavController extends Base
{
    const DAV_METHODS = ['GET', 'POST', 'PUT', 'MOVE', 'COPY', 'DELETE', 'MKCOL',
                         'LOCK', 'UNLOCK',
                         'HEAD', 'OPTIONS', 'PROPFIND', 'PROPPATCH'];

    const WEBDAV_ROOT = '/remote.php/dav';
    // user-based access, authenticated via:
    //   - session (web)
    //   - auth:basic (user with file manager, app with sync client)
    const PREFIX_USER = '/remote.php/dav/files/';
    // chunking v2 API, authenticated via:
    //   - auth:basic (app with sync client)
    const PREFIX_CHUNKING_V2 = '/remote.php/dav/uploads/';
    // share-based access, authenticated via:
    //   - session (web)
    //   - NOT password in POST (only SharesController::shareView handles that)
    //   - auth:basic (file manager)
    const PREFIX_SHARE = '/public.php/webdav';

    public static function MakeUserPath(string|int $login, string $path)
    {
        return Path::Join(self::PREFIX_USER, (string)$login, $path);
    }


    #[Auto\Route(self::WEBDAV_ROOT, method: 'HEAD')]
    public function androiddav(Response $res, Request $req)
    {
        // don't need to do anything, just respond
    }

    #[Auto\Route(self::PREFIX_USER . '<login>', method:self::DAV_METHODS)]
    #[Auto\Route(self::PREFIX_USER . '<login>/<path*>', method:self::DAV_METHODS, slash:true)]
    public function userdav(Response $res, Request $req, string $login, array $path=[])
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initRequest($req, $res, $this->session, $login)) {
            $identity->sendChallenge($req, $res);
            return;
        }
        $context = new Context($this->app, $identity);
        $server = new ServerAdapter($context->getFilesView(), $req, $res);
        if ($identity->type == Identity::TYPE_UNAUTHENTICATED) {
            $server->sendException(new DAV\Exception\NotAuthenticated('Not authenticated'));
        } else {
            $context->setupStorage();
            TreeUtil::setupServer($server, TreeUtil::requestBaseUri($req, $path));
            $server->addPlugin(new DAV\Browser\Plugin());
            $server->start();
        }
        // Report that Sabre already sent the response
        return false;
    }

    #[Auto\Route(self::PREFIX_SHARE, method:self::DAV_METHODS)]
    #[Auto\Route(self::PREFIX_SHARE . '/<path*>', method:self::DAV_METHODS, slash:true)]
    public function sharedav(Response $res, Request $req, array $path=[])
    {
        // fetch the token from HTTP Basic auth
        $basic = new Auth\Basic('Public Share', $req, $res);
        if ($cred = $basic->getCredentials()) {
            [$token, $pass] = $cred;
            $share = InodeShares::findOne(['token' => $token]);
            if (is_null($share->id)) {
                $res->standardResponse(404);
                return;
            }
        } else {
            $basic->requireLogin();
            return;
        }

        // check the password, if any
        $identity = new Identity($token);
        if (!$identity->initShare($this->session, $share, $pass, true)) {
            $basic->requireLogin();
            return;
        }

        // setup and launch server
        $context = new Context($this->app, $identity);

        $server = new ServerAdapter($context->getFilesView(), $req, $res);
        $context->setupStorage();
        TreeUtil::setupServer($server, TreeUtil::requestBaseUri($req, $path));
        $server->start();
        // Report that Sabre already sent the response
        return false;
    }
}