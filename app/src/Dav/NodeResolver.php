<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav;

use App\Dav\FS\Node;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Model\Users;
use Nepf2\Util\Arr;
use Nepf2\Util\Path;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\Tree;

class NodeResolver
{
    const MAX_INCREMENT_ATTEMPTS = 1000;

    private static array $inodeSharedCache = [];
    private static array $inodeOwnerNameCache = [];

    /**
     * Walk up the parent tree while staying with the same owner.
     * Returns null if the path at any point before the root changes ownership.
     *
     * @param int $inode
     * @return string|null
     */
    public static function InodeStrictPath(int $inode): ?string
    {
        if (!($node = Inodes::Find($inode, ['owner_id', 'parent_id', 'name', 'deleted'])))
            return null;
        $owner = $node->owner_id;
        $names = [is_null($node->deleted) ? $node->name : $node->getQualifiedName()];
        while (!is_null($node->parent_id)) {
            if (!($node = Inodes::Find($node->parent_id, ['owner_id', 'parent_id', 'name'])) ||
                $node->owner_id !== $owner)
                return null;
            array_unshift($names, is_null($node->deleted) ? $node->name : $node->getQualifiedName());
        }
        // the final node is the root node, which has no real name in paths regardless of what the DB says
        $names[0] = '';
        return implode('/', $names);
    }

    public static function InodeTreeOwnedBy(int $inode, int $owner): bool
    {
        $id = $inode;
        while ($id) {
            if (!($node = Inodes::Find($id, ['parent_id', 'owner_id'])) || $node->owner_id !== $owner)
                return false;
            if (is_null($node->parent_id))
                break;
            $id = $node->parent_id;
        }
        return true;
    }

    /**
     * Walk up the parent tree until we either find the $parentInode or the subtree's root
     *
     * @param int $inode
     * @param int $parentInode
     * @return bool
     */
    public static function InodeInSubtree(int $inode, int $parentInode): bool
    {
        while ($inode > 0) {
            if ($inode == $parentInode)
                return true;
            $node = Inodes::Find($inode, ['parent_id']);
            if (is_null($node->parent_id))
                break;
            $inode = $node->parent_id;
        }
        return false;
    }

    public static function InodeVisibleIn(int $inode, int $parentInode, array &$recursionTrap=[]): bool
    {
        // if shared folders are looped, checking one could find itself again. in that case, just abort
        if (isset($recursionTrap[$inode]))
            return false;
        $recursionTrap[$inode] = true;
        // go up until we find:
        //   0 -> return false
        //   parentNode -> return true
        //   a node which has a ShareInodes
        //     then note this SharedInode
        // finally, test each recorded SharedInode if no simple solution was found
        $sharesInHierarchy = [];
        while ($inode) {
            if ($inode === $parentInode)
                return true;

            $parent = Inodes::Find($inode, ['id', 'parent_id'])->parent_id;

            // is this node shared anywhere?
            $sharedAs = self::$inodeSharedCache[$inode] ??=
                InodeShares::findBy(['inode_id' => $inode])->toArray(['column' => 'id']);
            $sharesInHierarchy = Arr::Union($sharesInHierarchy, $sharedAs);

            // continue up the main tree
            $inode = $parent ?? 0;
        }
        // the main tree did not hit anything. if this inode is in any shared folder, each mountpoint of each shared
        // folder could be inside $parentNode
        foreach ($sharesInHierarchy as $share) {
            foreach (InodeShares::FindInodes($share) as $linkNode) {
                if (self::InodeVisibleIn($linkNode->id, $parentInode, $recursionTrap))
                    return true;
            }
        }
        // the $inode is provably not reachable from $parentInode
        return false;
    }

    public static function InodeIncrementalName(Inodes $parent, string $name): ?string
    {
        if (!$parent->findChild($name))
            return $name;
        ['filename' => $filename, 'extension' => $extension] = pathinfo($name);
        if ($extension)
            $extension = '.' . $extension;
        for ($i=1; $i<self::MAX_INCREMENT_ATTEMPTS; $i++) {
            $newName = ltrim("$filename ($i)$extension");
            if (!$parent->findChild($newName))
                return $newName;
        }
        return null;
    }

    public static function UserGetName(int $user_id): string
    {
        return self::$inodeOwnerNameCache[$user_id] ??= (Users::findOne(['id' => $user_id])->username ?? '');
    }

    public static function TreeNodeForPath(Tree $tree, string $uri): ?INode
    {
        try {
            return $tree->getNodeForPath($uri);
        } catch (NotFound $e) {
            return null;
        }
    }

    /**
     * Fetch all Nodes along a path and return an array of App\Dav\Node instances or null starting from the first
     * element that could not be found.
     * Uses Tree::getNodeForPath's builtin cache.
     *
     * @param Tree $tree
     * @param string $uri
     * @return array
     */
    public static function TreeGetNodesOfPath(Tree $tree, string $uri): array
    {
        // add one dummy for array_reduce's last iteration
        $parts = explode('/', Path::Canonicalize($uri) . '/');
        $pathNodes = [];
        $lastFound = true;
        array_reduce($parts, function ($path, $nextPart) use ($tree, &$pathNodes, &$lastFound) {
            $node = $lastFound ? self::TreeNodeForPath($tree, $path) : null;
            // once one node was not found, don't bother doing lookups for any descendents
            $lastFound = !is_null($node);
            $pathNodes[] = $node;
            return $path . '/' . $nextPart;
        }, '');
        return $pathNodes;
    }

    /**
     * Return the numeric Inode ids of all nodes in $nodes that are backed by Inodes.
     *
     * @param Node[] $nodes
     * @return int[]
     */
    public static function NodesToInodes(array $nodes): array
    {
        $nodes = array_filter($nodes, fn($n) => $n instanceof Node);
        return array_map(fn($n) => $n->getInodeId(), $nodes);
    }


}