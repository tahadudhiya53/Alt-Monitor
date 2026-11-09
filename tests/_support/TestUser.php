<?php

namespace Tahadudhiya\AltMonitor\Tests\_support;

use craft\elements\User;

/**
 * A user whose permissions are stated outright rather than stored.
 *
 * Craft answers `can()` from the database, which would mean saving a user and its permission
 * rows for every test. Assigning permissions directly keeps tests free of database writes, and
 * overriding only the one method access checks rely on keeps Craft's own authorization running
 * exactly as it does in production.
 */
class TestUser extends User
{
    /** @var string[] Permissions this user holds. */
    public array $grantedPermissions = [];

    public function can(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->grantedPermissions, true);
    }
}
