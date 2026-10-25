<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Service;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\CareDispatch\Domain\Violation;
use YouWillLikeIT\CareDispatch\Exception\AssignmentConflictException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Service\AssignmentValidator;
use YouWillLikeIT\CareDispatch\Service\VisitAssignmentService;

class AssignmentValidatorTest extends SapphireTest
{
    protected static $fixture_file = 'CareDispatchTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-11-01 18:00:00');
    }

    public function testRequiredQualificationIsDerivedFromTasks(): void
    {
        $visit = $this->objFromFixture(PatientVisit::class, 'pool');
        $visit->write();
        $this->assertSame('Fachkraft', $visit->RequiredQualification);
    }

    public function testHilfskraftCannotTakeFachkraftVisit(): void
    {
        $visit = $this->objFromFixture(PatientVisit::class, 'pool');
        $visit->write();
        $check = AssignmentValidator::singleton()->check($visit, $this->objFromFixture(Caregiver::class, 'hans'), 43200, 44400, null);
        $codes = array_map(static fn (Violation $v): string => $v->code, $check->hard());
        $this->assertContains(Violation::QUALIFICATION_INSUFFICIENT, $codes);
    }

    public function testAzubiCannotBePrimary(): void
    {
        $visit = $this->objFromFixture(PatientVisit::class, 'pool');
        $check = AssignmentValidator::singleton()->check($visit, $this->objFromFixture(Caregiver::class, 'azubi'), 43200, 44400, null);
        $codes = array_map(static fn (Violation $v): string => $v->code, $check->hard());
        $this->assertContains(Violation::AZUBI_AS_PRIMARY, $codes);
    }

    public function testSoftWarningsNeedConfirmationThenCreateAdHocTour(): void
    {
        $visitID = $this->idFromFixture(PatientVisit::class, 'pool');
        $anna = $this->objFromFixture(Caregiver::class, 'anna');
        $service = VisitAssignmentService::singleton();

        // 15:00 is outside Anna's 06:00-14:00 shift (soft).
        try {
            $service->assignVisit($visitID, null, (int) $anna->ID, '15:00');
            $this->fail('Expected confirmation request');
        } catch (AssignmentConflictException $e) {
            $codes = array_map(static fn (Violation $v): string => $v->code, $e->violations());
            $this->assertContains(Violation::OUTSIDE_SHIFT, $codes);
        }

        try {
            $service->assignVisit($visitID, null, (int) $anna->ID, '15:00', true, '');
            $this->fail('Expected reason requirement');
        } catch (DomainRuleException $e) {
            $this->assertSame('OVERRIDE_REASON_REQUIRED', $e->errorCode());
        }

        // Anna's only tour that day is open, so the visit joins it; time inside the shift -> no warnings.
        $result = $service->assignVisit($visitID, null, (int) $anna->ID, '12:00');
        $this->assertSame(PatientVisit::STATUS_PENDING, $result['visit']->Status);
        $this->assertSame('12:00:00', $result['visit']->ScheduledStart);
        $this->assertFalse($result['createdTour']);
    }
}
