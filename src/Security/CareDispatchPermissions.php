<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Security;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;

final class CareDispatchPermissions implements PermissionProvider
{
    /** Einsatzplan: board, assignment, SOS, route optimisation, tour templates. */
    public const DISPATCH = 'PFLEGE_DISPATCH_ACCESS';

    /** Field staff: own tour, check-in/out, visit status. */
    public const MOBILE = 'PFLEGE_MOBILE_ACCESS';

    /** Stammdaten: patients (address, contacts, wishes), service catalogue. */
    public const MASTERDATA = 'PFLEGE_MASTERDATA_EDIT';

    /** Pflegeplanung: care plan positions (Vorbehaltsaufgabe der Pflegefachkraft). */
    public const CAREPLAN = 'PFLEGE_CAREPLAN_EDIT';

    /** Dienstplan, Abwesenheiten, Zeitkorrekturen. */
    public const ROSTER = 'PFLEGE_ROSTER_EDIT';

    /** Team: staff profiles, accounts, roles, passwords. */
    public const STAFF = 'PFLEGE_STAFF_MANAGE';

    /** Read the audit trail. */
    public const AUDIT = 'PFLEGE_AUDIT_VIEW';

    /** Company settings (office location, board options, access log). */
    public const SETTINGS = 'PFLEGE_SETTINGS_EDIT';

    public const ALL = [
        self::DISPATCH,
        self::MOBILE,
        self::MASTERDATA,
        self::CAREPLAN,
        self::ROSTER,
        self::STAFF,
        self::AUDIT,
        self::SETTINGS,
    ];

    public function providePermissions(): array
    {
        $category = 'Pflegedienst Einsatzplanung';
        $definitions = [
            self::DISPATCH => ['Einsatzplan und Touren', 'Board, Einsätze zuweisen, SOS, Routen optimieren, Tourvorlagen pflegen.'],
            self::MOBILE => ['Mobile Ansicht für Pflegekräfte', 'Eigene Tour, Kommen/Gehen, Einsätze starten und abschließen.'],
            self::MASTERDATA => ['Stammdaten', 'Patienten anlegen und bearbeiten, Leistungskatalog pflegen.'],
            self::CAREPLAN => ['Pflegeplanung', 'Pflegeplan-Positionen der Patienten bearbeiten (Pflegefachkraft).'],
            self::ROSTER => ['Dienstplan und Zeiten', 'Dienste, Abwesenheiten und Zeitbuchungen bearbeiten.'],
            self::STAFF => ['Team und Benutzerkonten', 'Mitarbeiter anlegen, Rollen zuweisen, Passwörter setzen.'],
            self::AUDIT => ['Änderungsprotokoll einsehen', 'Vollständiges Audit-Log aller Änderungen und Ereignisse.'],
            self::SETTINGS => ['Einstellungen', 'Firmenstandort, Board-Optionen, Zugriffsprotokoll.'],
        ];
        $out = [];
        $sort = 10;
        foreach ($definitions as $code => [$name, $help]) {
            $out[$code] = ['name' => $name, 'category' => $category, 'help' => $help, 'sort' => $sort];
            $sort += 10;
        }

        return $out;
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

    /** @return array<string, bool> code => granted, for the client */
    public static function flags(?Member $member = null): array
    {
        $out = [];
        foreach (self::ALL as $code) {
            $out[$code] = self::check($code, $member);
        }

        return $out;
    }
}
