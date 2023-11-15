<?php

namespace App\Controller;

use App\Browser\PresentationBase;
use App\Dav\Context;
use App\Dav\FS\Directory;
use App\Dav\FS\File;
use App\Dav\FS\Node;
use App\Dav\Identity;
use App\Dav\NodeResolver;
use App\Dav\Perm;
use App\Dav\PermSet;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Model\Users;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;
use Nepf2\Util\Random;
use Sabre\DAV;

#[Auto\RoutePrefix('/ajax')]
class FrontendAPIController extends Base
{
    private function initAPIRequest(Response $res, ?Identity &$identity, ?Context &$context): bool
    {
        if (!$this->isLoggedIn()) {
            $res->standardResponse(403);
            return false;
        }
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initSession($this->session)) {
            $res->standardResponse(403);
            return false;
        }
        $context = new Context($this->app, $identity);
        return true;
    }

    private function translateException(Response $res, \Exception $exception): void
    {
        if ($exception instanceof DAV\Exception) {
            $res->setStatus(400);
            if ($exception instanceof DAV\Exception\Forbidden)
                $res->setStatus(403);
        } else {
            $res->setStatus(500);
        }
        $res->setJSON([
            'error' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }

    #[Auto\Route('/version/list', method:['POST'])]
    public function versionList(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['file']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        if (!$requestedNode instanceof File) {
            $res->standardResponse(500);
            return;
        }

        $result = [
            'current' => $requestedNode->getCurrentVersion()->id
        ];
        foreach ($requestedNode->getVersions() as $version) {
            $result['versions'][] = [
                'id' => $version->id,
                'created' => $version->created,
                'creator' => NodeResolver::UserGetName($version->creator_id),
                'size' => $version->size,
                'name' => $version->name ?? ''
            ];
        }
        $res->setJSON($result);
    }

    #[Auto\Route('/version/restore', method:['POST'])]
    public function versionRestore(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['file']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        if (!$requestedNode instanceof File) {
            $res->standardResponse(500);
            return;
        }

        if (!$requestedNode->getPerms()->has(Perm::CAN_WRITE)) {
            $res->standardResponse(403);
            return;
        }

        $verid = $params['version'];
        $ts = $params['ts'];
        if (!is_integer($verid) || !is_integer($ts)) {
            $res->standardResponse(400);
            return;
        }

        try {
            if ($version = $requestedNode->getVersion($verid, $ts)) {
                $success = $requestedNode->restoreVersion($version);
                $res->setJSON([
                    'result' => $success
                ]);
            } else {
                $res->standardResponse(400);
            }
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/new/folder', method:['POST'])]
    public function fileNewFolder(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $parent = Path::Canonicalize($params['parent']);
        $name = $params['name'];

        try {
            $parentNode = $context->getFilesView()->getNodeForPath($parent);
        } catch (DAV\Exception\NotFound $e) {
            $res->standardResponse(404);
            return;
        }

        if (!($parentNode instanceof Directory) ||
            ($parentNode->childExists($name))) {
            $res->standardResponse(400);
            return;
        }

        if (!$parentNode->getInnerPerms()->has(Perm::CAN_MKDIR)) {
            $res->standardResponse(403);
            return;
        }

        try {
            $parentNode->createDirectory($name);
            $childNode = $parentNode->getChild($name);

            $res->setJSON([
                'result' => true,
                'inode' => $childNode->getInodeId(),
                'path' => Path::ExpandRelative($parent, $childNode->getName()),
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/paste', method:['POST'])]
    public function filePaste(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $parent = Path::Canonicalize($params['parent']);

        if (!in_array($params['operation'], ['copy', 'move'])) {
            $res->standardResponse(400);
            return;
        }

        try {
            $parentNode = $context->getFilesView()->getNodeForPath($parent);
        } catch (DAV\Exception\NotFound $e) {
            $res->standardResponse(404);
            return;
        }

        if (!($parentNode instanceof Directory)) {
            $res->standardResponse(400);
            return;
        }

        $files = $params['files'] ?? [];

        $pairs = [];
        $needsDir = false;
        $needsFile = false;
        foreach ($files as $file) {
            $node = $context->getNode($file);
            if (is_null($node)) {
                $res->standardResponse(404);
                return;
            }
            $needsDir |= $node instanceof Directory;
            $needsFile |= $node instanceof File;
            if ($params['operation'] === 'move') {
                // moving a file where it already is, is a noop
                if ($node->getInode(false)->parent_id === $parentNode->getInodeId(true))
                    continue;
                // but moving it anywhere else needs perms
                if (!$node->getPerms()->has(Perm::CAN_MOVE)) {
                    $res->standardResponse(403);
                    return;
                }
            }
            if (is_null($newName = NodeResolver::InodeIncrementalName($parentNode->getInode(true), $node->getName()))) {
                $res->standardResponse(400);
                return;
            }
            $pairs[] = [$file, Path::Join($parent, $newName)];
        }

        try {
            if (count($pairs)) {
                if (($needsFile && !$parentNode->getInnerPerms()->has(Perm::CAN_ADDFILE)) ||
                    ($needsDir && !$parentNode->getInnerPerms()->has(Perm::CAN_MKDIR))) {
                    $res->standardResponse(403);
                    return;
                }

                $context->setupStorage();

                $handler = match ($params['operation']) {
                    'copy' => $context->getFilesView()->copy(...),
                    'move' => $context->getFilesView()->move(...),
                };
                foreach($pairs as [$source, $dest]) {
                    $handler($source, $dest);
                }
            }

            $res->setJSON([
                'result' => true,
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/rename', method:['POST'])]
    public function fileRename(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $path = Path::Canonicalize($params['path']);
        $name = $params['name'];

        try {
            $node = $context->getFilesView()->getNodeForPath($path);
        } catch (DAV\Exception\NotFound $e) {
            $res->standardResponse(404);
            return;
        }

        if (!$node->getPerms()->has(Perm::CAN_RENAME)) {
            $res->standardResponse(403);
            return;
        }

        try {
            Inodes::db()->beginTransaction();
            $node->setName($name);
            Inodes::db()->commit();

            $res->setJSON([
                'result' => true,
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/delete', method:['POST'])]
    public function fileDelete(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $files = $params['files'] ?? [];

        $nodes = [];
        foreach ($files as $file) {
            $node = $context->getNode($file);
            if (is_null($node)) {
                $res->standardResponse(404);
                return;
            }
            if (!$node->getPerms()->has(Perm::CAN_DELETE)) {
                $res->standardResponse(403);
                return;
            }
            $nodes[] = $node;
        }

        try {
            $done = [];
            foreach ($nodes as $node) {
                $node->delete();
                $done[] = $node->getInodeId();
            }

            $res->setJSON([
                'result' => true,
                'list' => $done
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/restore', method:['POST'])]
    public function fileRestore(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $files = $params['files'] ?? [];

        $nodes = [];
        foreach ($files as $file) {
            $node = $context->getNode($file);
            if (is_null($node)) {
                $res->standardResponse(404);
                return;
            }
            if (!$node->getPerms()->has(Perm::CAN_DELETE)) {
                $res->standardResponse(403);
                return;
            }
            $nodes[] = $node;
        }

        try {
            $done = [];
            foreach ($nodes as $node) {
                if ($node->restore())
                    $done[] = $node->getInodeId();
            }

            $res->setJSON([
                'result' => true,
                'list' => $done
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/file/remove', method:['POST'])]
    public function fileRemove(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        $params = $req->jsonBody();
        $files = $params['files'] ?? [];

        $nodes = [];
        foreach ($files as $file) {
            $node = $context->getNode($file);
            if (is_null($node)) {
                $res->standardResponse(404);
                return;
            }
            if (!$node->getPerms()->has(Perm::CAN_DELETE)) {
                $res->standardResponse(403);
                return;
            }
            $nodes[] = $node;
        }

        try {
            $context->setupStorage();

            $done = [];
            foreach ($nodes as $node) {
                $deleted = $node->removeRecursive();
                array_push($done, ...$deleted);
            }

            $res->setJSON([
                'result' => true,
                'list' => $done
            ]);
        } catch (\Exception $e) {
            $this->translateException($res, $e);
        }
    }

    #[Auto\Route('/share/list', method:['POST'])]
    public function shareList(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        if (is_null($userid = $identity->getUserId())) {
            // can only properly validate for users
            $res->standardResponse(403);
            return;
        }

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['file']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        $result = [];
        foreach (InodeShares::EnumInodeShares($requestedNode->getInode(false), $userid) as $share) {
            $attr = [
                'id' => $share->id,
                'perms' => $share->permissions,
            ];
            if ($share->sharer_id === $userid) {
                // a share generated by this user
                $attr['editable'] = true;
                $attr['sharedWith'] = [];
                foreach (InodeShares::UsersWithAccess($share->id) as $user) {
                    $attr['sharedWith'][] = $user->username;
                }
                $attr['token'] = $share->token;
                $attr['hasPassword'] = !is_null($share->password);
                $attr['presentation'] = $share->presentation;
            } else {
                // a share received from somewhere
                $attr['editable'] = false;
                $attr['sharedBy'] = NodeResolver::UserGetName($share->sharer_id);
            }
            $result[] = $attr;
        }
        usort($result, fn ($a, $b) => ($a['editable'] <=> $b['editable']) ?: ($a['token']??'' <=> $b['token']??''));
        $res->setJSON([
            'canShare' => $requestedNode->isOwned() ||
                          ($requestedNode->getPerms()->has(Perm::IS_SHARED|Perm::CAN_RESHARE)),
            'shares' => $result
        ]);
    }

    private function updateShareFromRequest(InodeShares $share, array $params, bool $existing): void
    {
        // change internal shares
        if (isset($params['addShare']) || isset($params['removeShare'])) {
            $current = array_map(fn ($user) => $user->username, InodeShares::UsersWithAccess($share->id));
            $toAdd = array_filter($params['addShare'] ?? [],
                                  fn ($username) => !in_array($username, $current));
            $toRemove = array_filter($params['removeShare'] ?? [],
                                     fn ($username) => in_array($username, $current));

            foreach ($toRemove as $username) {
                $user = Users::findOne(['username' => $username]);
                if (is_null($user->id))
                    continue;
                $share->removeLinksInUser($user);
            }

            foreach ($toAdd as $username) {
                $user = Users::findOne(['username' => $username]);
                if (is_null($user->id))
                    continue;
                $share->createLinkInUser($user);
            }
        }

        // enable/disable public share
        if (isset($params['publicLink'])) {
            if ($params['publicLink']) {
                if (is_null($share->token)) {
                    // new shares start with random token
                    $share->token = Random::TokenStr();
                }
            } else {
                $share->token = null;
                $share->password = null;
            }
        }

        // modify a public share
        if (isset($share->token)) {
            if (isset($params['customLink']) && $params['customLink'] !== $share->token) {
                // changing the token
                if (InodeShares::getTotal(['token' => $params['customLink']]) == 0)
                    $share->token = $params['customLink'];
            }

            if (isset($params['clearPassword']))
                $share->password = null;
            elseif (isset($params['setPassword']))
                $share->password = password_hash($params['setPassword'], PASSWORD_DEFAULT);

            if (isset($params['presentation'])) {
                $avail = PresentationBase::AvailablePresentations();
                if (in_array($params['presentation'], $avail, true))
                    $share->presentation = $params['presentation'];
            }
        }

        if (isset($params['perms'])) {
            $perm = new PermSet($params['perms']);
            $share->permissions = (string)$perm->without(~Perm::INHERITABLE_MASK);
        }

        if ($share->isDirty()) {
            $share->modified = time();
            $share->save();
            // update the etags of all nodes pointing to share
            foreach(InodeShares::FindInodes($share->id) as $inode) {
                $inode->contentChanged(save: true);
            }
        }
    }

    #[Auto\Route('/share/new', method:['POST'])]
    public function shareNew(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        if (is_null($userid = $identity->getUserId())) {
            // can only properly validate for users
            $res->standardResponse(403);
            return;
        }

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['path']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        $targetInode = $requestedNode->getInodeId(false);
        if ($requestedNode->isLink()) {
            // is re-sharing this share allowed?
            if (!$requestedNode->getPerms()->has(Perm::CAN_RESHARE)) {
                $res->standardResponse(403);
                return;
            }
            // if so, the thing we're sharing is actually the target
            $targetInode = $requestedNode->getInodeId(true);
        } else {
            // creating a new share is a write-like operation
            if (!$requestedNode->getPerms()->has(Perm::CAN_WRITE)) {
                $res->standardResponse(403);
                return;
            }
        }

        Inodes::db()->beginTransaction();
        $share = new InodeShares(['inode_id' => $targetInode,
                                  'sharer_id' => $userid,
                                  'modified' => time()]);
        $share->save();
        $this->updateShareFromRequest($share, $params, false);
        Inodes::db()->commit();

        $res->setJSON([
            'result' => true,
            'id' => $share->id,
        ]);
    }

    private function getExistingShare(Response $res, Node $requestedNode, int $userid, int $shareId, ?InodeShares &$share): bool
    {
        $share = InodeShares::findOne(['id' => $shareId]);
        if (is_null($share->id)) {
            $res->standardResponse(404);
            return false;
        }
        if ($share->inode_id !== $requestedNode->getInodeId(false)) {
            $res->standardResponse(404);
            return false;
        }
        if ($share->sharer_id !== $userid) {
            $res->standardResponse(403);
            return false;
        }
        return true;
    }

    #[Auto\Route('/share/edit', method:['POST'])]
    public function shareEdit(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        if (is_null($userid = $identity->getUserId())) {
            // can only properly validate for users
            $res->standardResponse(403);
            return;
        }

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['path']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        if (!$this->getExistingShare($res, $requestedNode, $userid, $params['share'], $share))
            return;

        if (!$requestedNode->getPerms()->has(Perm::CAN_WRITE)) {
            $res->standardResponse(403);
            return;
        }

        Inodes::db()->beginTransaction();
        $this->updateShareFromRequest($share, $params, true);
        Inodes::db()->commit();

        $res->setJSON([
            'result' => true,
            'id' => $share->id,
        ]);
    }

    #[Auto\Route('/share/remove', method:['POST'])]
    public function shareUnshare(Response $res, Request $req)
    {
        if (!$this->initAPIRequest($res, $identity, $context))
            return;

        if (is_null($userid = $identity->getUserId())) {
            // can only properly validate for users
            $res->standardResponse(403);
            return;
        }

        $params = $req->jsonBody();
        $requestedNode = $context->getNode($params['path']);
        if (is_null($requestedNode)) {
            $res->standardResponse(404);
            return;
        }

        if (!$this->getExistingShare($res, $requestedNode, $userid, $params['share'], $share))
            return;

        if (!$requestedNode->getPerms()->has(Perm::CAN_WRITE)) {
            $res->standardResponse(403);
            return;
        }

        Inodes::db()->beginTransaction();
        $share->removeShareAndLinks();
        Inodes::db()->commit();

        $res->setJSON([
            'result' => true,
        ]);
    }

}