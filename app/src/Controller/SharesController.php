<?php

namespace App\Controller;

use App\Browser\PresentationBase;
use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\Identity;
use App\Dav\IIndexableCollection;
use App\Dav\NodeResolver;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Model\Users;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;

class SharesController extends Base
{
    #[Auto\Route('/shares')]
	public function shareList(Response $res, Request $req)
	{
        if (!$this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $userid = $this->session->userid;

        function fmtShare(InodeShares $share, Inodes $inode): array {
            $fullPath = NodeResolver::InodeStrictPath($inode->id);
            return [
                'id' => $share->id,
                'modified' => $share->modified,
                'inode' => $inode->id,
                'name' => $inode->name,
                'path' => $fullPath,
                'perms' => $share->permissions,
            ];
        }

        // collect created shares, showing this user's inode for metadata
        $createdShares = [];
        foreach (InodeShares::EnumUserShares($userid) as [$share, $inode]) {
            $createdShares[] = fmtShare($share, $inode);
        }

        // collect received shares, showing this user's inode for metadata
        $receivedShares = [];
        foreach (InodeShares::EnumReceivedShares($userid) as [$share, $inode]) {
            $sharer = Users::findOne(['id' => $share->sharer_id]);
            $attrs = fmtShare($share, $inode);
            $attrs['sharedBy'] = $sharer->username;
            $receivedShares[] = $attrs;
        }
        usort($createdShares, fn ($a, $b) => $a['modified'] <=> $b['modified']);
        usort($receivedShares, fn ($a, $b) => $a['modified'] <=> $b['modified']);
        $view = $this->initTemplateView('shares_list.twig');
        $view->set('created', $createdShares);
        $view->set('received', $receivedShares);
        $res->setBody($view->render());
    }

    #[Auto\Route('/s/<token>', method:['GET', 'POST'])]
    #[Auto\Route('/s/<token>/<path*>', method:['GET', 'POST'], slash:true)]
    public function shareView(Response $res, Request $req, string $token, array $path=[])
    {
        $share = InodeShares::findOne(['token' => $token]);
        if (is_null($share->id)) {
            $res->standardResponse(404);
            return;
        }

        $browser = PresentationBase::CreateForPresentation($share->presentation, $this, $req, $res);
        $identity = new Identity($token);
        $formPassword = ($req->getMethod() === 'POST' && $req->int('authenticate')) ? $req->str('password') : null;
        if (!$identity->initShare($this->session, $share, $formPassword, false)) {
            $res->standardResponse(403);
            $browser->emitShareLogin($share);
            return;
        }

        $context = new Context($this->app, $identity);

        if (is_null($requestedItem = $browser->initRequestedItem($context, $path))) {
            $res->standardResponse(404);
            $browser->emitNotFound();
            return;
        }

        $browser->initServer();

        if ($requestedItem instanceof IIndexableCollection) {
            $browser->emitDirectoryIndex($context, false);
        } elseif ($requestedItem instanceof File) {
            $browser->serveFileDirect($context);
            return false;
        }
    }
}
