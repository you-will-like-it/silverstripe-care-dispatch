<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Service;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\VisitTask;
use YouWillLikeIT\CareDispatch\Service\TourInterruptService;

class TourInterruptServiceTest extends SapphireTest
{
    protected static $fixture_file = 'CareDispatchTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-11-02 08:20:00');
    }

    public function testRunningVisitRequiresOutcome(): void
    {
        $tour = $this->objFromFixture(DailyTour::class, 'tour');
        $this->expectException(DomainRuleException::class);
        TourInterruptService::singleton()->interrupt((int) $tour->ID, 'Unfall', null, 'pdl');
    }

    public function testSplitSendsOpenVisitsToPoolAndKeepsCompleted(): void
    {
        $tour = $this->objFromFixture(DailyTour::class, 'tour');
        $result = TourInterruptService::singleton()->interrupt((int) $tour->ID, 'Fahrzeugpanne', 'not_completed', 'pdl');

        $done = PatientVisit::get()->byID($this->idFromFixture(PatientVisit::class, 'done'));
        $running = PatientVisit::get()->byID($this->idFromFixture(PatientVisit::class, 'running'));
        $pending = PatientVisit::get()->byID($this->idFromFixture(PatientVisit::class, 'pending'));

        $this->assertSame(DailyTour::STATUS_INTERRUPTED, DailyTour::get()->byID($tour->ID)->Status);
        $this->assertSame(PatientVisit::STATUS_COMPLETED, $done->Status);
        $this->assertSame((int) $tour->ID, (int) $done->DailyTourID);

        foreach ([$running, $pending] as $visit) {
            $this->assertSame(PatientVisit::STATUS_UNASSIGNED, $visit->Status);
            $this->assertSame(0, (int) $visit->DailyTourID);
        }
        $this->assertNull($running->ActualStart, 'Aborted visit loses its ActualStart ...');
        $abort = AuditEntry::get()->filter(['Action' => 'visit_aborted_on_sos', 'SubjectID' => $running->ID])->first();
        $this->assertNotNull($abort, '... which is preserved in the audit trail');
        $this->assertSame('2026-11-02 08:05:00', $abort->getChangesArray()['actualStart']);
        $this->assertSame(
            VisitTask::STATUS_DONE,
            $this->objFromFixture(VisitTask::class, 'runningWash')->Status,
            'Documented tasks stay done'
        );
        $this->assertEqualsCanonicalizing([(int) $running->ID, (int) $pending->ID], $result['pooledVisitIds']);
    }

    public function testCompletedOutcomeKeepsRunningVisit(): void
    {
        $tour = $this->objFromFixture(DailyTour::class, 'tour');
        TourInterruptService::singleton()->interrupt((int) $tour->ID, 'Erkrankung', 'completed', 'mobile');

        $running = PatientVisit::get()->byID($this->idFromFixture(PatientVisit::class, 'running'));
        $this->assertSame(PatientVisit::STATUS_COMPLETED, $running->Status);
        $this->assertSame('2026-11-02 08:20:00', $running->ActualEnd);
    }
}
