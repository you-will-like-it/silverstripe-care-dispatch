<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Security;

use InvalidArgumentException;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Fixed role presets, materialised as Silverstripe groups.
 *
 * Practice in ambulant care:
 * - Pflegedienstleitung (PDL, incl. Stellvertretung): Pflegeplanung (Vorbehaltsaufgabe der
 *   Pflegefachkraft), Touren-/Einsatzplanung, Dienstplan, Team. In small services also Verwaltung.
 * - Verwaltung / Büro: Patientenaufnahme (Stammdaten), Mitarbeiter und Konten, Dienstplan und
 *   Zeiten für die Lohnabrechnung, Leistungskatalog. No care plan, no dispatch, no audit.
 * - Pflegekraft: mobile view only.
 *
 * Each managed member has exactly one role. A member can only grant roles whose permissions
 * they hold themselves (no privilege escalation); ADMIN may grant everything.
 */
final class Roles
{
    public const PDL = 'pdl';
    public const OFFICE = 'verwaltung';
    public const FIELD = 'pflegekraft';

    /** role => [group code, title, description, permission codes] */
    public const DEFINITIONS = [
        self::PDL => [
            'care-dispatch-role-pdl',
            'Pflegedienstleitung',
            'Planung, Pflegeplanung, Dienstplan, Team, Protokoll, Einstellungen.',
            [
                CareDispatchPermissions::DISPATCH,
                CareDispatchPermissions::MASTERDATA,
                CareDispatchPermissions::CAREPLAN,
                CareDispatchPermissions::ROSTER,
                CareDispatchPermissions::STAFF,
                CareDispatchPermissions::AUDIT,
                CareDispatchPermissions::SETTINGS,
                // A PDL with a caregiver profile also works in the field.
                CareDispatchPermissions::MOBILE,
            ],
        ],
        self::OFFICE => [
            'care-dispatch-role-verwaltung',
            'Verwaltung',
            'Patientenstammdaten, Team und Konten, Dienstplan und Zeiten, Leistungskatalog.',
            [
                CareDispatchPermissions::MASTERDATA,
                CareDispatchPermissions::ROSTER,
                CareDispatchPermissions::STAFF,
            ],
        ],
        self::FIELD => [
            'care-dispatch-role-pflegekraft',
            'Pflegekraft',
            'Mobile Ansicht: eigene Tour, Kommen/Gehen, Einsätze dokumentieren.',
            [CareDispatchPermissions::MOBILE],
        ],
    ];

    /** Creates/updates the role groups and their permissions (idempotent). */
    public static function ensureGroups(): void
    {
        foreach (self::DEFINITIONS as $role => [$code, $title, $description, $permissions]) {
            $group = self::group($role);
            $changed = false;
            if ($group === null) {
                $group = Group::create();
                $group->Code = $code;
                $changed = true;
            }
            if ($group->Title !== $title || $group->Description !== $description) {
                $group->Title = $title;
                $group->Description = $description;
                $changed = true;
            }
            if ($changed) {
                $group->write();
            }
            $existing = Permission::get()->filter('GroupID', $group->ID)->column('Code');
            foreach ($permissions as $permission) {
                if (!in_array($permission, $existing, true)) {
                    Permission::grant((int) $group->ID, $permission);
                }
            }
        }
    }

    public static function group(string $role): ?Group
    {
        $definition = self::DEFINITIONS[$role] ?? throw new InvalidArgumentException(sprintf('Unknown role "%s".', $role));

        return Group::get()->filter('Code', $definition[0])->first();
    }

    public static function title(string $role): string
    {
        return self::DEFINITIONS[$role][1] ?? $role;
    }

    /** @return string[] */
    public static function permissions(string $role): array
    {
        return self::DEFINITIONS[$role][3] ?? [];
    }

    /** Role of a member, or null if they have none of the managed roles. */
    public static function roleOf(Member $member): ?string
    {
        $codes = $member->DirectGroups()->column('Code');
        foreach (self::DEFINITIONS as $role => [$code]) {
            if (in_array($code, $codes, true)) {
                return $role;
            }
        }

        return null;
    }

    /** Assigns exactly one role (or none) to the member. */
    public static function assign(Member $member, ?string $role): void
    {
        self::ensureGroups();
        foreach (array_keys(self::DEFINITIONS) as $candidate) {
            $group = self::group($candidate);
            if ($group === null) {
                continue;
            }
            if ($candidate === $role) {
                $group->Members()->add($member);
            } else {
                $group->Members()->remove($member);
            }
        }
    }

    /** Whether $actor may give $role to somebody (subset rule). */
    public static function canGrant(Member $actor, string $role): bool
    {
        if (Permission::checkMember($actor, 'ADMIN')) {
            return true;
        }
        foreach (self::permissions($role) as $permission) {
            if (!Permission::checkMember($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $actor may manage $member at all: never ADMINs (unless actor is ADMIN), never members
     * whose current role exceeds the actor's own permissions.
     */
    public static function canManage(Member $actor, Member $member): bool
    {
        if (Permission::checkMember($actor, 'ADMIN')) {
            return true;
        }
        if (Permission::checkMember($member, 'ADMIN')) {
            return false;
        }
        $role = self::roleOf($member);

        return $role === null || self::canGrant($actor, $role);
    }

    /** @return array<int, array{value:string, label:string, description:string, permissions:string[]}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $role => [, $title, $description, $permissions]) {
            $out[] = ['value' => $role, 'label' => $title, 'description' => $description, 'permissions' => $permissions];
        }

        return $out;
    }
}
