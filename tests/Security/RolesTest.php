<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Security;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareDispatch\Exception\AccessDeniedException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Security\Roles;
use YouWillLikeIT\CareDispatch\Service\StaffService;

class RolesTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function member(string $email, ?string $role): Member
    {
        $member = Member::create(['FirstName' => 'T', 'Surname' => $email, 'Email' => $email]);
        $member->write();
        Roles::assign($member, $role);

        return $member;
    }

    public function testSubsetRule(): void
    {
        Roles::ensureGroups();
        $pdl = $this->member('pdl@test.test', Roles::PDL);
        $office = $this->member('office@test.test', Roles::OFFICE);

        $this->assertTrue(Roles::canGrant($pdl, Roles::OFFICE));
        $this->assertTrue(Roles::canGrant($office, Roles::FIELD));
        $this->assertFalse(Roles::canGrant($office, Roles::PDL), 'Verwaltung must not create a PDL');
        $this->assertFalse(Roles::canManage($office, $pdl));
        $this->assertSame(Roles::OFFICE, Roles::roleOf($office));
    }

    public function testStaffServiceGuards(): void
    {
        Roles::ensureGroups();
        $pdl = $this->member('pdl2@test.test', Roles::PDL);
        $office = $this->member('office2@test.test', Roles::OFFICE);
        $service = StaffService::singleton();

        $created = $service->save($office, null, [
            'firstName' => 'Neue',
            'lastName' => 'Kraft',
            'email' => 'neu@test.test',
            'password' => 'Pflege-Test-2026!',
            'role' => Roles::FIELD,
            'caregiver' => ['qualification' => 'Hilfskraft', 'weeklyHours' => 20],
        ]);
        $this->assertSame(Roles::FIELD, Roles::roleOf($created));

        try {
            $service->save($office, null, ['firstName' => 'X', 'lastName' => 'Y', 'email' => 'x@test.test', 'password' => 'Pflege-Test-2026!', 'role' => Roles::PDL]);
            $this->fail('Office must not grant PDL');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DomainRuleException::class);
        $service->deactivate($pdl, (int) $pdl->ID);
    }
}
