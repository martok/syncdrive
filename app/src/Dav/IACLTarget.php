<?php

namespace App\Dav;

interface IACLTarget
{
    /**
     * Get the permissions set on the object itself.
     *
     * @return PermSet
     */
    public function getPerms(): PermSet;

    /**
     * Get the permissions in effect on the inside of the object.
     * This is only different from `getPerms` for shared directories.
     *
     * @return PermSet
     */
    public function getInnerPerms(): PermSet;

    /**
     * Restrict the current permission set using the parent's declared permissions.
     *
     * @param PermSet $declared
     * @return void
     */
    public function inheritPerms(PermSet $declared): void;

}