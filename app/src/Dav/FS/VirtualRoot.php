<?php

namespace App\Dav\FS;

use App\Dav\Context;
use App\Dav\IACLTarget;
use App\Dav\IIndexableCollection;
use App\Dav\Perm;
use App\Dav\PermSet;
use Sabre\DAV\SimpleCollection;

class VirtualRoot extends SimpleCollection implements IIndexableCollection, IACLTarget
{
    private PermSet $effectivePermissions;
    private Context $ctx;

    public function __construct(Context $context, array $children = [])
    {
        parent::__construct('', $children);
        $this->ctx = $context;
        $this->effectivePermissions = new PermSet(Perm::DEFAULT_OWNED);
    }

    public function hasChildren(): bool
    {
        return !!count($this->children);
    }

    public function getChildrenWithDeleted(): array
    {
        return $this->getChildren();
    }

    public function getPerms(): PermSet
    {
        return $this->effectivePermissions;
    }

    public function getInnerPerms(): PermSet
    {
        return $this->getPerms()->without(Perm::FLAG_MASK);
    }

    public function inheritPerms(PermSet $declared): void
    {
        $this->effectivePermissions = $this->effectivePermissions->inherit($declared->value());
        // also apply to all virtual children
        foreach ($this->children as $child)
            if ($child instanceof IACLTarget)
                $child->inheritPerms($this->effectivePermissions);
    }
}