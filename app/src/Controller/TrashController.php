<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Controller;

use App\Dav\NodeResolver;
use App\Model\Inodes;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;

class TrashController extends Base
{
    #[Auto\Route('/trash')]
	public function trashList(Response $res, Request $req)
	{
        if (!$this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $userid = $this->session->userid;

        $inodes = Inodes::findBy(['owner_id' => $userid, 'deleted-' => null],
                                 ['order' => 'deleted DESC']);

        $files = [];
        foreach ($inodes as $inode) {
            $fullPath = NodeResolver::InodeStrictPath($inode->id);
            if (is_null($fullPath))
                continue;
            list($parent) = Path::Pop($fullPath);
            $parentNoQuali = preg_replace('#/.d+:#', '/', $parent);
            $files[] = [
                'id' => $inode->id,
                'name' => $inode->name,
                'deleted' => $inode->deleted,
                'isFolder' => $inode->type == Inodes::TYPE_COLLECTION,
                'size' => $inode->size,
                'path' => $fullPath,
                'originPath' => $parent,
                'originDisplay' => $parentNoQuali,
            ];
        }
        $view = $this->initTemplateView('trash_list.twig');
        $view->set('files', [
            'list' => $files
        ]);
        $res->setBody($view->render());
    }
}
