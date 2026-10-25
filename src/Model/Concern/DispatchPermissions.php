<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model\Concern;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Shared CMS/ORM permission model for all care dispatch records.
 *
 * - View: dispatch, master data or audit permission.
 * - Edit/Create/Delete: the permission code in the class's `edit_permission` config
 *   (MASTERDATA for planning data, DISPATCH for operational data).
 *
 * Field staff never touch records through the ORM permission layer directly; the mobile API
 * authorises them against "their own" records in the service layer.
 */
trait DispatchPermissions
{
    public function canView($member = null): bool
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return (bool) Permission::check(
            [CareDispatchPermissions::DISPATCH, CareDispatchPermissions::MASTERDATA, CareDispatchPermissions::AUDIT],
            'any',
            $member
        );
    }

    public function canEdit($member = null): bool
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return $this->checkEditPermission($member);
    }

    public function canDelete($member = null): bool
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return $this->checkEditPermission($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        $extended = $this->extendedCan(__FUNCTION__, $member, $context);
        if ($extended !== null) {
            return $extended;
        }

        return $this->checkEditPermission($member);
    }

    private function checkEditPermission(Member|int|null $member): bool
    {
        $code = (string) (static::config()->get('edit_permission') ?: CareDispatchPermissions::MASTERDATA);

        return (bool) Permission::check($code, 'any', $member);
    }
}
