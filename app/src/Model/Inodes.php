<?php

namespace App\Model;

use App\Dav\PermSet;
use App\Exception;
use App\ObjectStorage\ObjectInfo;

class Inodes extends \Pop\Db\Record
{
    const TYPE_COLLECTION = 1;
    const TYPE_FILE = 2;
    const TYPE_INTERNAL_SHARE = 3;

    /*
     * int id
     * int/null parent_id
     * int/null owner_id User
     * int type
     * varchar(255) name
     * int/null deleted utctime
     * int modified utctime
     * varchar/null etag
     * int/null current_version FileVersions
     * int/null link_target SharedInodes
     */

    public static function New(int $type, string $name, ?int $modified=null,
                               ?int $owner=null, ?int $parent=null): static
    {
        $columns = [
            'type' => $type,
            'name' => $name,
            'modified' => $modified ?? time()
        ];
        if (!is_null($owner))
            $columns['owner_id'] = $owner;
        if (!is_null($parent))
            $columns['parent_id'] = $parent;
        $new = new static($columns);
        $new->contentChanged();
        return $new;
    }

    public static function Find(?int $id, ?array $columns=null): ?self
    {
        $filter = null;
        if (!is_null($columns)) {
            if (!in_array('id', $columns))
                $columns[] = 'id';
            $filter = ['select' => $columns];
        }
        if (!is_null($id) &&
            ($node = Inodes::findOne(['id' => $id], $filter)) &&
            !is_null($node->id))
            return $node;
        return null;
    }

    public static function IsRegularNodeType(int $type): bool
    {
        switch ($type) {
            case self::TYPE_COLLECTION:
            case self::TYPE_FILE:
                return true;
            default:
                return false;
        }
    }

    public static function NodeContentChanged(?int $inodeId): void
    {
        if ($parentNode = Inodes::Find($inodeId))
            $parentNode->contentChanged(save:true);
    }

    public function save(array $columns = null): void
    {
        $dirty = $this->getDirty();
        $etagChanged = ($dirty['new']['etag'] ?? '') !== ($dirty['old']['etag'] ?? '');
        parent::save($columns);
        // cascade changes up the tree so that the client can detect modified files
        if ($etagChanged) {
            $containers = [$this->parent_id];
            // cascade into shares, needed for Nextcloud client sync
            foreach (InodeShares::findBy(['inode_id' => $this->id]) as $share) {
                foreach (InodeShares::FindInodes($share->id) as $inode) {
                    if (!in_array($inode->id, $containers)) {
                        $containers[] = $inode->id;
                    }
                }
            }
            foreach ($containers as $container) {
                self::NodeContentChanged($container);
            }
        }
    }

    public function contentChanged(bool $save = false): void
    {
        $this->modified = time();
        $this->etag = $this->computeEtag();
        if ($save)
            $this->save();
    }

    public function setDeleted(bool $deleted): void
    {
        if ($deleted) {
            $this->deleted = is_null($this->deleted) ? time() : min($this->deleted, time());
        } else
            $this->deleted = null;
        $this->save();
    }

    public function deleteWithRefs(): void
    {
        // delete any lock specifically on this inode
        $q = InodeLocks::db()->createSql();
        $q->delete(InodeLocks::table())
            ->where('inode_id = :inode_id');
        InodeLocks::execute($q, ['inode_id' => $this->id]);
        // delete all props
        $q->reset()
            ->delete(InodeProps::table())
            ->where('inode_id = :inode_id');
        InodeProps::execute($q, ['inode_id' => $this->id]);
        $this->delete();
    }

    /**
     * Compute the currently valid Etag for this file.
     * Assumes that all relevant child elements have been updated and saved.
     *
     * @return string the etag value
     * @throws Exception
     */
    public function computeEtag(): string
    {
        if (is_null($this->id)) {
            // if the Inode hasn't been saved yet, the etag is meaningless and all we need is something
            // to detect a change later
            return sha1(implode($this->toArray()));
        }
        switch ($this->type) {
            case self::TYPE_COLLECTION:
                // for folders, etag is derived from the set of non-deleted children
                // use the stored etag to avoid cascading queries
                $children = Inodes::findBy(['parent_id' => $this->id, 'deleted' => null])->toArray(['column' => 'etag']);
                // sort to ensure consistent results
                sort($children);
                return sha1(sprintf('%d::%s', $this->id, implode("::", $children)));
            case self::TYPE_FILE:
                // for files, etag is derived from the storage object
                $current = $this->getCurrentVersion();
                return sha1(sprintf('%d::%d::%s', $this->id, $current->size, $current->object_id));
            case self::TYPE_INTERNAL_SHARE:
                // shared objects have the same etag as their targets
                $linkTarget = $this->getLinkInfo()['target'];
                return $linkTarget->etag;
        }
        throw new Exception("Unknown inode type: $this->type");
    }

    public function getChildren(bool $includeDeleted=false): array
    {
        if ($this->type !== self::TYPE_COLLECTION)
            return [];
        return Inodes::findBy(array_merge(['parent_id' => $this->id],
                                          $includeDeleted ? [] : ['deleted' => null]),
                              ['order' => ['type ASC', 'name ASC']])->getItems();
    }

    /**
     * Return a named child or null. If a specific inode is requested, it is returned if otherwise
     * matching. If no inode is requested, the current non-deleted child is returned.
     *
     * @param string $name File name to search
     * @param int|null $inode Inode to resolve to
     * @return Inodes|null The matched Inode
     */
    public function findChild(string $name, ?int $inode=null): ?Inodes
    {
        if ($this->type !== self::TYPE_COLLECTION)
            return null;
        $node = Inodes::findOne(array_merge(['parent_id' => $this->id, 'name' => $name],
                                            is_null($inode) ? ['deleted' => null] : ['id' => $inode]));
        return isset($node->id) ? $node : null;
    }

    public function hasChildren(bool $includeDeleted=false): bool
    {
        if ($this->type !== self::TYPE_COLLECTION)
            return false;
        $node = Inodes::findOne(array_merge(['parent_id' => $this->id],
                                $includeDeleted ? [] : ['deleted' => null]));
        return isset($node->id);
    }

    /**
     * @return FileVersions
     * @throws Exception
     */
    public function getCurrentVersion(): FileVersions
    {
        $current = FileVersions::findOne(['id' => $this->current_version_id]);
        if (!isset($current->id))
            throw new Exception("Inode $this->id has invalid current_version");
        return $current;
    }

    public function newVersion(ObjectInfo $object, int $creator, ?int $timestamp=null): void
    {
        // create file version for the incoming data
        $version = FileVersions::New($this, $object->size, $object->object, $creator);
        $version->save();
        // reflect change on self
        $this->current_version_id = $version->id;
        $this->contentChanged();
        if (!is_null($timestamp))
            $this->modified = $timestamp;
        $this->save();
    }

    public function getLinkInfo(): ?array
    {
        switch ($this->type) {
            case self::TYPE_INTERNAL_SHARE:
                $share = InodeShares::findOne(['id' => $this->link_target]);
                if (!isset($share->id))
                    return null;
                if (!($inode = Inodes::Find($share->inode_id)))
                    return null;
                return [
                    'target' => $inode,
                    'permissions' => new PermSet($share->permissions ?? ''),
                ];
            default:
                return null;
        }
    }
}