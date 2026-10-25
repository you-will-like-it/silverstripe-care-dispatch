<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Security\Roles;
use YouWillLikeIT\CareDispatch\Service\StaffService;

/**
 * /api/admin/staff – Team: Mitarbeiter, Konten, Rollen (STAFF).
 */
class StaffApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET list' => 'listStaff',
        'GET caregivers' => 'caregivers',
        'POST create' => 'createStaff',
        'POST $ID!/password' => 'password',
        'POST $ID!/deactivate' => 'deactivate',
        'POST $ID!' => 'updateStaff',
    ];

    private static array $allowed_actions = ['listStaff', 'caregivers', 'createStaff', 'password', 'deactivate', 'updateStaff'];

    private static array $dependencies = [
        'staff' => '%$' . StaffService::class,
    ];

    public StaffService $staff;

    /** GET list?inactive=1 */
    public function listStaff(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::STAFF);
            Roles::ensureGroups();
            $actor = $this->currentMember();
            $ids = [];
            foreach (array_keys(Roles::DEFINITIONS) as $role) {
                $group = Roles::group($role);
                if ($group !== null) {
                    $ids = array_merge($ids, $group->Members()->column('ID'));
                }
            }
            $ids = array_merge($ids, Caregiver::get()->column('MemberID'));
            $members = Member::get()->filter('ID', array_values(array_unique(array_filter($ids))) ?: [0])->sort(['Surname' => 'ASC', 'FirstName' => 'ASC']);
            $out = [];
            foreach ($members as $member) {
                $dto = $this->staff->present($member, $actor);
                $active = $dto['role'] !== null;
                if (!$active && !$request->getVar('inactive')) {
                    continue;
                }
                $dto['active'] = $active;
                $out[] = $dto;
            }

            return [
                'staff' => $out,
                'roles' => array_map(static fn (array $r): array => $r + ['grantable' => Roles::canGrant($actor, $r['value'])], Roles::options()),
                'qualifications' => QualificationLevel::options(),
            ];
        });
    }

    /** GET caregivers – active caregivers for selects (any back-office permission). */
    public function caregivers(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => [
            'caregivers' => array_map(static fn (Caregiver $c): array => [
                'id' => (int) $c->ID,
                'name' => $c->getTitle(),
                'qualification' => (string) $c->QualificationLevel,
                'qualificationLabel' => $c->getQualificationLabel(),
                'weeklyHours' => (float) $c->ContractWeeklyHours,
                'color' => $c->BoardColor ?: null,
            ], Caregiver::get()->filter('IsActive', true)->toArray()),
        ]);
    }

    public function createStaff(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::STAFF);
            $actor = $this->currentMember();
            $member = $this->staff->save($actor, null, $this->body());

            return ['member' => $this->staff->present($member, $actor)];
        }, 201);
    }

    public function updateStaff(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::STAFF);
            $actor = $this->currentMember();
            $member = $this->staff->save($actor, $this->routeID($request), $this->body());

            return ['member' => $this->staff->present($member, $actor)];
        });
    }

    /** POST $ID/password {password} – set by PDL/Verwaltung, no forced change. */
    public function password(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::STAFF);
            $body = $this->body();
            $password = is_string($body['password'] ?? null) ? $body['password'] : '';
            $this->staff->setPassword($this->currentMember(), $this->routeID($request), $password);

            return ['ok' => true];
        });
    }

    public function deactivate(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::STAFF);
            $this->staff->deactivate($this->currentMember(), $this->routeID($request));

            return ['ok' => true];
        });
    }
}
