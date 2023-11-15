<?php

namespace App\Controller;

use App\Browser\BrowserMain;
use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\Identity;
use App\Dav\IIndexableCollection;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Sabre\DAV;

class BrowseController extends Base
{

    #[Auto\Route('/browse', method:['GET'])]
    #[Auto\Route('/browse/<path*>', method:['GET'], slash:true)]
    public function browse(Response $res, Request $req, array $path=[])
    {
        if (!$this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initSession($this->session)) {
            $res->redirect('/');
            return;
        }
        $context = new Context($this->app, $identity);
        if (($opt = $req->int('deleted', -1)) !== -1)
            $this->session->showDeleted = !!$opt;

        $browser = new BrowserMain($this, $req, $res);
        if (is_null($requestedItem = $browser->initRequestedItem($context, $path))) {
            $res->standardResponse(404);
            $browser->emitNotFound();
            return;
        }

        $server = $browser->initServer();

        if ($requestedItem instanceof File &&
            ($verid = $req->int('version')) && ($ts = $req->int('ts'))) {
            if (!$browser->initRequestedVersion($verid, $ts)) {
                $server->sendException(new DAV\Exception\BadRequest('Invalid version specified'));
                return false;
            }
        }

        if ($requestedItem instanceof IIndexableCollection) {
            $browser->emitDirectoryIndex($context, $this->session->showDeleted);
        } elseif ($requestedItem instanceof File) {
            $browser->serveFileDirect($context);
            return false;
        }
    }



}