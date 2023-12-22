<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Dav\Backend;

use App\Dav\FS\File;
use App\Dav\FS\Node;
use App\Model\InodeProps;
use App\Model\Inodes;
use Sabre\DAV\Browser\PropFindAll;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Tree;
use Sabre\DAV\Xml\Property\Complex;

class PropsBackend implements \Sabre\DAV\PropertyStorage\Backend\BackendInterface
{
    private const NOT_RETURNED_PROPERTIES = [
        '{DAV:}getcontentlength',
        '{DAV:}getcontenttype',
        '{DAV:}getetag',
        '{DAV:}quota-used-bytes',
        '{DAV:}quota-available-bytes',
        '{http://owncloud.org/ns}permissions',
        '{http://owncloud.org/ns}downloadURL',
        '{http://owncloud.org/ns}dDC',
        '{http://owncloud.org/ns}size',
        '{http://nextcloud.org/ns}is-encrypted',
    ];

    private const PROP_MS_MTIME = '{urn:schemas-microsoft-com:}Win32LastModifiedTime';
    private const PROP_MS_MTIME_FORMAT = 'D, d M Y H:i:s T';

    const VT_STRING = 1;
    const VT_XML = 2;
    const VT_OBJECT = 3;

    private readonly Tree $tree;

    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    private static function decodeValue(int $type, string $encoded): mixed
    {
        switch ($type) {
            case self::VT_STRING:
                return $encoded;
            case self::VT_XML:
                return new Complex($encoded);
            case self::VT_OBJECT:
                return unserialize($encoded);
            default:
                return null;
        }
    }

    private static function encodeValue(mixed $value): array
    {
        if (is_scalar($value)) {
            return [self::VT_STRING, $value];
        } elseif ($value instanceof Complex) {
            return [self::VT_XML, $value->getXml()];
        } else {
            return [self::VT_OBJECT, serialize($value)];
        }
    }

    /**
     * @inheritDoc
     */
    public function propFind($path, PropFind $propFind)
    {
        $node = $this->tree->getNodeForPath($path);
        // first, process explicit things
        $propFind->handle('{http://owncloud.org/ns}permissions', function() use ($node) {
            if ($node instanceof Node) {
                $perms = $node->getPerms();
                return (string)$perms;
            }
            return '';
        });
        $propFind->handle('{http://owncloud.org/ns}size', function() use ($node) {
            if ($node instanceof Node) {
                return $node->getSize();
            }
            return '';
        });
        $propFind->handle('{DAV:}getetag', function() use ($node) {
            if ($node instanceof Node) {
                return $node->getETag();
            }
            return null;
        });
        $propFind->handle(self::PROP_MS_MTIME, function() use ($node) {
            if ($node instanceof Node) {
                $stamp = $node->getLastModified();
                $dt = new \DateTimeImmutable('@'.$stamp);
                return $dt->format(self::PROP_MS_MTIME_FORMAT);
            }
            return null;
        });
        $propFind->handle('{http://owncloud.org/ns}id', function() use ($node, $path) {
            // FileId should remain the same when a file is moved and be completely unique otherwise
            // The client uses this to detect if a move occurred on the server to replay the same move
            // locally iff the etag is still the same => use inode id but ignore version, as that changes etag as well.
            if ($node instanceof Node) {
                return $node->getFileID();
            }
            return md5($path);
        });

        // the browser plugin doesn't set the allprops requestType
        $isAllProps = $propFind->isAllProps() || ($propFind instanceof PropFindAll);
        $requestedProps = $propFind->get404Properties();
        $requestedProps = array_diff($requestedProps, self::NOT_RETURNED_PROPERTIES);
        $requestedProps = array_values($requestedProps);
        if (!$isAllProps && 0 === count($requestedProps)) {
            return;
        }

        $conditions = ['inode_id' => $node->getInodeId()];
        if (!$isAllProps) {
            $conditions['name'] = $requestedProps;
        }

        $props = InodeProps::findBy($conditions);
        foreach ($props as $prop) {
            $propFind->set($prop->name, $this->decodeValue($prop->type, $prop->value));
        }
    }

    /**
     * @inheritDoc
     */
    public function propPatch($path, PropPatch $propPatch)
    {

        $node = $this->tree->getNodeForPath($path);
        $inode = $node->getInodeId();
        $propPatch->handle(self::PROP_MS_MTIME, function($stamp) use ($inode) {
            // Map {urn:schemas-microsoft-com:}Win32LastModifiedTime to INodes->modified for consistent
            // read and write using the different methods
            if (is_null($stamp)) {
                $newTime = time();
            } else {
                if (false!== ($newTime = \DateTimeImmutable::createFromFormat(self::PROP_MS_MTIME_FORMAT, $stamp)))
                    $newTime = $newTime->getTimestamp();
            }
            if ($newTime) {
                $node = Inodes::Find($inode);
                $node->modified = $newTime;
                $node->save();
            }
            return true;
        });
        $propPatch->handleRemaining(function($properties) use ($inode) {
            foreach ($properties as $name => $value) {
                $prop = InodeProps::findOne(['inode_id'=> $inode, 'name' => $name]);
                if (is_null($value)) {
                    // delete property if it exists
                    if (isset($prop->id))
                        $prop->delete();
                } else {
                    [$type, $encoded] = $this->encodeValue($value);
                    if (isset($prop->id)) {
                        // property exists, update it
                        $prop->type = $type;
                        $prop->value = $encoded;
                    } else {
                        // new property
                        $prop = new InodeProps([
                            'inode_id' => $inode,
                            'name' => $name,
                            'type' => $type,
                            'value' => $encoded
                        ]);
                    }
                    $prop->save();
                }
            }
            return true;
        });
    }

    /**
     * @inheritDoc
     */
    public function delete($path)
    {
        // delete happens from Node's side (after emptying trash)
    }

    /**
     * @inheritDoc
     */
    public function move($source, $destination)
    {
        // move for our files doesn't change the inode number
    }
}