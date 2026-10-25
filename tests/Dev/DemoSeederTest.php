<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Dev;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareDispatch\Dev\DemoDataset;
use YouWillLikeIT\CareDispatch\Dev\DemoSeeder;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;

class DemoSeederTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testSeedsPastLiveAndFutureDays(): void
    {
        $seeder = new DemoSeeder(null, 2026);
        $result = $seeder->run('2026-11-04', 1, 1, 10 * 3600, 'Pflege-Demo-2026!');

        $this->assertCount(count(DemoDataset::CAREGIVERS) + 2, $result['accounts']);
        $this->assertSame(count(DemoDataset::CAREGIVERS), Caregiver::get()->count());
        $this->assertSame(
            count(DemoDataset::CAREGIVERS) + 2,
            Member::get()->filter('Email:EndsWith', '@' . DemoDataset::EMAIL_DOMAIN)->count()
        );

        // Past day: nothing left open, every remaining visit done or cancelled.
        $past = PatientVisit::get()->filter('VisitDate', '2026-11-03');
        $this->assertGreaterThan(0, $past->filter('Status', PatientVisit::STATUS_COMPLETED)->count());
        $this->assertSame(0, $past->filter('Status', [PatientVisit::STATUS_PENDING, PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_UNASSIGNED])->count());
        $this->assertSame(0, TimeEntry::get()->filter(['CheckIn:LessThan' => '2026-11-04 00:00:00', 'Status:not' => TimeEntry::STATUS_COMPLETED])->count());

        // Presentation day at 10:00: some done, something running, pool and unstaffed tour for the demo.
        $today = PatientVisit::get()->filter('VisitDate', '2026-11-04');
        $this->assertGreaterThan(0, $today->filter('Status', PatientVisit::STATUS_COMPLETED)->count());
        $this->assertGreaterThan(0, $today->filter('Status', PatientVisit::STATUS_PENDING)->count());
        $this->assertGreaterThan(0, $today->filter('Status', PatientVisit::STATUS_UNASSIGNED)->count());
        $this->assertTrue(DailyTour::get()->filter(['TourDate' => '2026-11-04', 'CaregiverID' => 0])->exists());

        // Future day: planned only.
        $future = PatientVisit::get()->filter('VisitDate', '2026-11-05');
        $this->assertGreaterThan(0, $future->count());
        $this->assertSame(0, $future->filter('Status', PatientVisit::STATUS_COMPLETED)->count());

        $this->assertTrue(AuditEntry::get()->filter('Action', 'visit_completed')->exists());
        $this->assertSame(0, $result['warnings'], 'Replay of the field work must not hit any rule violation');

        $seeder->reset();
        $this->assertFalse(DemoSeeder::hasData());
    }
}
