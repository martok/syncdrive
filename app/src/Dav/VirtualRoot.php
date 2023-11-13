<?php

namespace App\Dav;

use Sabre\DAV\SimpleCollection;

class VirtualRoot extends SimpleCollection implements IIndexableCollection, IACLTarget
{
    private PermSet $effectivePermissions;

    public function __construct($name, array $children = [])
    {
        parent::__construct($name, $children);
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