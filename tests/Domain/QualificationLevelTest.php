<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Domain;

use SilverStripe\Dev\SapphireTest;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Domain\Weekdays;

class QualificationLevelTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testRanking(): void
    {
        $this->assertTrue(QualificationLevel::Fachkraft->satisfies(QualificationLevel::Fachkraft));
        $this->assertTrue(QualificationLevel::Fachkraft->satisfies(QualificationLevel::Hilfskraft));
        $this->assertTrue(QualificationLevel::PflegehelferLG1->satisfies(QualificationLevel::Hilfskraft));
        $this->assertFalse(QualificationLevel::PflegehelferLG1->satisfies(QualificationLevel::Fachkraft));
        $this->assertFalse(QualificationLevel::Hilfskraft->satisfies(QualificationLevel::PflegehelferLG1));
    }

    public function testAzubiIsNeverPrimary(): void
    {
        $this->assertFalse(QualificationLevel::Azubi->canActAsPrimary());
        $this->assertFalse(QualificationLevel::Azubi->satisfies(QualificationLevel::Hilfskraft));
    }

    public function testMax(): void
    {
        $this->assertSame(QualificationLevel::Fachkraft, QualificationLevel::max(QualificationLevel::Hilfskraft, QualificationLevel::Fachkraft));
    }

    public function testWeekdays(): void
    {
        $this->assertSame('["1","3","5"]', Weekdays::normalize('5,1,3,3'));
        $this->assertSame('["2"]', Weekdays::normalize(['2', 9, 0]));
        $this->assertTrue(Weekdays::contains('["1","3"]', 3));
        $this->assertFalse(Weekdays::contains('1,3', 2));
        $this->assertSame('täglich', Weekdays::label(Weekdays::all()));
        $this->assertSame('Mo, Fr', Weekdays::label('1,5'));
    }
}
