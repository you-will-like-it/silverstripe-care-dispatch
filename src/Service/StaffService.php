<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Exception\AccessDeniedException;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Security\Roles;

/**
 * Team management from the frontend: member account + role + optional caregiver profile.
 *
 * Guards:
 * - a role can only be granted if the actor holds all of its permissions
 * - ADMIN accounts and accounts with a higher role are read-only for the actor
 * - nobody can remove their own role or deactivate themselves
 * - role "pflegekraft" always has a caregiver profile
 */
class StaffService
{
    use Injectable;

    private static array $dependencies = [
        'audit' => '%$' . AuditLogger::class,
    ];

    public AuditLogger $audit;

    /**
     * @param array<string, mixed> $data  firstName, lastName, email, role, password (create), isCaregiver, caregiver{...}
     */
    public function save(Member $actor, ?int $memberID, array $data): Member
    {
        return Transaction::run(function () use ($actor, $memberID, $data): Member {
            $isNew = $memberID === null;
            $member = $isNew ? Member::create() : (Member::get()->byID($memberID) ?? throw NotFoundException::for('Benutzer', $memberID));
            if (!$isNew && !Roles::canManage($actor, $member)) {
                throw AccessDeniedException::because('Dieses Konto darfst du nicht bearbeiten.');
            }

            $role = $this->role($data['role'] ?? null);
            if ($role === null) {
                throw BadRequestException::because('Bitte eine Rolle wählen. Zum Entziehen des Zugangs „Deaktivieren“ verwenden.');
            }
            if (!Roles::canGrant($actor, $role)) {
                throw AccessDeniedException::because(sprintf('Die Rolle „%s“ darfst du nicht vergeben.', Roles::title($role)));
            }
            if (!$isNew && (int) $member->ID === (int) $actor->ID && $role !== Roles::roleOf($member)) {
                throw new DomainRuleException('OWN_ROLE', 'Die eigene Rolle kann nicht geändert werden.');
            }

            $firstName = trim((string) ($data['firstName'] ?? ''));
            $lastName = trim((string) ($data['lastName'] ?? ''));
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($firstName === '' || $lastName === '') {
                throw BadRequestException::because('Vor- und Nachname sind Pflichtfelder.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw BadRequestException::because('Bitte eine gültige E-Mail-Adresse angeben (wird als Login verwendet).');
            }
            $duplicate = Member::get()->filter('Email', $email)->exclude('ID', $member->ID ?: 0)->exists();
            if ($duplicate) {
                throw new DomainRuleException('EMAIL_TAKEN', 'Diese E-Mail-Adresse wird bereits verwendet.');
            }

            $member->FirstName = $firstName;
            $member->Surname = $lastName;
            $member->Email = $email;
            if ($isNew) {
                $password = (string) ($data['password'] ?? '');
                if ($password === '') {
                    throw BadRequestException::because('Bitte ein Passwort festlegen.');
                }
                $member->Password = $password;
                $member->Locale = $member->Locale ?: 'de_DE';
            }
            $member->write();
            Roles::assign($member, $role);

            $isCaregiver = $role === Roles::FIELD || filter_var($data['isCaregiver'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $caregiver = Caregiver::get()->filter('MemberID', $member->ID)->first();
            if ($isCaregiver) {
                $caregiver ??= Caregiver::create();
                $this->applyCaregiver($caregiver, (int) $member->ID, (array) ($data['caregiver'] ?? []));
            } elseif ($caregiver !== null && $caregiver->IsActive) {
                $this->deactivateCaregiver($caregiver);
            }

            $this->audit->event($member, $isNew ? 'staff_created' : 'staff_updated', [
                'memberId' => (int) $member->ID,
                'role' => $role,
                'caregiver' => $isCaregiver,
            ]);

            return $member;
        });
    }

    public function setPassword(Member $actor, int $memberID, string $password): void
    {
        Transaction::run(function () use ($actor, $memberID, $password): void {
            $member = Member::get()->byID($memberID) ?? throw NotFoundException::for('Benutzer', $memberID);
            if (!Roles::canManage($actor, $member)) {
                throw AccessDeniedException::because('Für dieses Konto darfst du kein Passwort setzen.');
            }
            if ($password === '') {
                throw BadRequestException::because('Passwort fehlt.');
            }
            $result = $member->changePassword($password);
            if (!$result->isValid()) {
                throw new ValidationException($result);
            }
            // Unlock after failed attempts.
            $member->LockedOutUntil = null;
            $member->FailedLoginCount = 0;
            $member->write();
            $this->audit->event($member, 'staff_password_set', ['memberId' => (int) $member->ID]);
        });
    }

    /** Removes the role (no more access) and deactivates the caregiver profile. The account is kept for the audit trail. */
    public function deactivate(Member $actor, int $memberID): void
    {
        Transaction::run(function () use ($actor, $memberID): void {
            $member = Member::get()->byID($memberID) ?? throw NotFoundException::for('Benutzer', $memberID);
            if ((int) $member->ID === (int) $actor->ID) {
                throw new DomainRuleException('OWN_ACCOUNT', 'Das eigene Konto kann nicht deaktiviert werden.');
            }
            if (!Roles::canManage($actor, $member)) {
                throw AccessDeniedException::because('Dieses Konto darfst du nicht deaktivieren.');
            }
            Roles::assign($member, null);
            $caregiver = Caregiver::get()->filter('MemberID', $member->ID)->first();
            if ($caregiver !== null && $caregiver->IsActive) {
                $this->deactivateCaregiver($caregiver);
            }
            $this->audit->event($member, 'staff_deactivated', ['memberId' => (int) $member->ID]);
        });
    }

    /** @param array<string, mixed> $data */
    private function applyCaregiver(Caregiver $caregiver, int $memberID, array $data): void
    {
        $qualification = (string) ($data['qualification'] ?? $caregiver->QualificationLevel ?: 'Hilfskraft');
        if (QualificationLevel::tryFrom($qualification) === null) {
            throw BadRequestException::because('Unbekannte Qualifikation.');
        }
        $caregiver->MemberID = $memberID;
        $caregiver->QualificationLevel = $qualification;
        if (array_key_exists('weeklyHours', $data)) {
            $caregiver->ContractWeeklyHours = (float) $data['weeklyHours'];
        }
        if (array_key_exists('drivingLicense', $data)) {
            $caregiver->DrivingLicense = filter_var($data['drivingLicense'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('color', $data)) {
            $caregiver->BoardColor = (string) $data['color'];
        }
        foreach (['startStreet' => 'StartStreet', 'startPostalCode' => 'StartPostalCode', 'startCity' => 'StartCity'] as $key => $field) {
            if (array_key_exists($key, $data)) {
                $caregiver->$field = trim((string) $data[$key]);
            }
        }
        $caregiver->IsActive = true;
        $caregiver->write();
    }

    private function deactivateCaregiver(Caregiver $caregiver): void
    {
        $open = DailyTour::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'Status' => [DailyTour::STATUS_IN_PROGRESS],
        ])->exists();
        if ($open) {
            throw new DomainRuleException('TOUR_RUNNING', $caregiver->getTitle() . ' hat eine laufende Tour. Bitte zuerst abschließen oder unterbrechen (SOS).');
        }
        foreach (DailyTour::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'Status' => [DailyTour::STATUS_DRAFT, DailyTour::STATUS_ASSIGNED],
        ]) as $tour) {
            $tour->CaregiverID = 0;
            $tour->refreshStatus();
            $tour->write();
        }
        $caregiver->IsActive = false;
        $caregiver->write();
    }

    private function role(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !array_key_exists($value, Roles::DEFINITIONS)) {
            throw BadRequestException::because('Unbekannte Rolle.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function present(Member $member, ?Member $actor = null): array
    {
        $caregiver = Caregiver::get()->filter('MemberID', $member->ID)->first();
        $role = Roles::roleOf($member);

        return [
            'id' => (int) $member->ID,
            'firstName' => (string) $member->FirstName,
            'lastName' => (string) $member->Surname,
            'name' => trim($member->FirstName . ' ' . $member->Surname),
            'email' => (string) $member->Email,
            'role' => $role,
            'roleLabel' => $role !== null ? Roles::title($role) : 'kein Zugang',
            'isAdmin' => (bool) Permission::checkMember($member, 'ADMIN'),
            'editable' => $actor === null || Roles::canManage($actor, $member),
            'isSelf' => $actor !== null && (int) $actor->ID === (int) $member->ID,
            'caregiver' => $caregiver === null ? null : [
                'id' => (int) $caregiver->ID,
                'isActive' => (bool) $caregiver->IsActive,
                'qualification' => (string) $caregiver->QualificationLevel,
                'qualificationLabel' => $caregiver->getQualificationLabel(),
                'weeklyHours' => (float) $caregiver->ContractWeeklyHours,
                'drivingLicense' => (bool) $caregiver->DrivingLicense,
                'color' => $caregiver->BoardColor ?: null,
                'startStreet' => $caregiver->StartStreet ?: '',
                'startPostalCode' => $caregiver->StartPostalCode ?: '',
                'startCity' => $caregiver->StartCity ?: '',
                'hasStartPoint' => $caregiver->getStartPoint() !== null,
            ],
        ];
    }
}
