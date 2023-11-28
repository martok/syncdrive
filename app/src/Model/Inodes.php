<?php

namespace App\Model;

use App\Dav\PermSet;
use App\Dav\TransferChecksums;
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
     * int size
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
        if (is_null($id))
            return null;
        $filter = null;
        if (!is_null($columns)) {
            if (!in_array('id', $columns))
                $columns[] = 'id';
            $filter = ['select' => $columns];
        }
        if (($node = Inodes::findOne(['id' => $id], $filter)) &&
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
        $metaChanged = $this->isMetadataChanged($dirty['old'], $dirty['new']);
        parent::save($columns);
        // cascade changes up the tree so that the client can detect modified files
        if ($metaChanged) {
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
        $this->updateMetadata();
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
     * Assuming all child nodes have up-to-date metadata, recompute the stored values for this node.
     */
    private function updateMetadata(): void
    {
        if (is_null($this->id)) {
            // if the Inode hasn't been saved yet:
            //  * the etag is meaningless and all we need is something to detect a change later
            //  * the size is zero since there can be no child nodes or associated FileVersions
            $newEtag = sha1(implode($this->toArray()));
            $newSize = 0;
        } else {
            switch ($this->type) {
                case self::TYPE_COLLECTION:
                    // for folders, etag is derived from the set of non-deleted children and their names
                    $childNodes = Inodes::findBy(['parent_id' => $this->id, 'deleted' => null]);
                    $newSize = 0;
                    $childInfos = [];
                    foreach ($childNodes as $child) {
                        $childInfos[] = sprintf('%s:%s', $child->etag, (string)$child->name);
                        $newSize += $child->size;
                    }
                    sort($childInfos);
                    $newEtag = sha1(sprintf('%d:%s', $this->id, implode(':', $childInfos)));
                    break;
                case self::TYPE_FILE:
                    // for files, etag is derived from the storage object
                    $current = $this->getCurrentVersion();
                    $newSize = $current->size;
                    $newEtag = sha1(sprintf('%d:%d:%s', $this->id, $current->size, $current->object_id));
                    break;
                case self::TYPE_INTERNAL_SHARE:
                    // shared objects change etag with their targets and with changes to permissions
                    $li = $this->getLinkInfo();
                    $newSize = $li['target']->size;
                    $newEtag = sha1(sprintf('%s:%s', $li['target']->etag, $li['permissions']));
                    break;
                default:
                    throw new Exception("Unknown inode type: $this->type");
            }
        }

        $this->etag = $newEtag;
        $this->size = $newSize;
    }

    private function isMetadataChanged(array $old, array $new): bool
    {
        return (($old['etag'] ?? '') !== ($new['etag'] ?? '')) && (($old['size'] ?? '') !== ($new['size'] ?? ''));
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
        if ($csstr = TransferChecksums::Serialize($object->checksums)) {
            // truncate to database field length
            $version->hashes = substr($csstr, 0, 255);
        }
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