<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Security;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;

final class CareDispatchPermissions implements PermissionProvider
{
    /** PDL / Disposition: board, assignment, SOS, route optimisation. */
    public const DISPATCH = 'PFLEGE_DISPATCH_ACCESS';

    /** Field staff: own tour, check-in/out, visit status. */
    public const MOBILE = 'PFLEGE_MOBILE_ACCESS';

    /** Master data: patients, care plans, tasks catalogue, templates, caregivers, shifts. */
    public const MASTERDATA = 'PFLEGE_MASTERDATA_EDIT';

    /** Read the audit trail. */
    public const AUDIT = 'PFLEGE_AUDIT_VIEW';

    public function providePermissions(): array
    {
        $category = 'Pflegedienst Einsatzplanung';

        return [
            self::DISPATCH => [
                'name' => 'PDL-Board und Disposition',
                'category' => $category,
                'help' => 'Touren zuweisen, Einsätze verschieben, Tour unterbrechen (SOS), Routen optimieren.',
                'sort' => 10,
            ],
            self::MOBILE => [
                'name' => 'Mobile Ansicht für Pflegekräfte',
                'category' => $category,
                'help' => 'Eigene Tour sehen, Kommen/Gehen buchen, Einsätze starten und abschließen.',
                'sort' => 20,
            ],
            self::MASTERDATA => [
                'name' => 'Stammdaten bearbeiten',
                'category' => $category,
                'help' => 'Patienten, Pflegepläne, Leistungskatalog, Tourvorlagen, Mitarbeiter und Dienste pflegen.',
                'sort' => 30,
            ],
            self::AUDIT => [
                'name' => 'Änderungsprotokoll einsehen',
                'category' => $category,
                'help' => 'Vollständiges Audit-Log aller Änderungen und Ereignisse lesen.',
                'sort' => 40,
            ],
        ];
    }

    public static function check(string $code, ?Member $member = null): bool
    {
        $member ??= Security::getCurrentUser();
        if ($member === null) {
            return false;
        }

        return (bool) Permission::checkMember($member, $code);
    }

    public static function canDispatch(?Member $member = null): bool
    {
        return self::check(self::DISPATCH, $member);
    }

    public static function canEditMasterData(?Member $member = null): bool
    {
        return self::check(self::MASTERDATA, $member);
    }
}
