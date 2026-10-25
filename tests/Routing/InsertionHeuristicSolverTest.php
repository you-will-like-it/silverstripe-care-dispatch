<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Tests\Routing;

use SilverStripe\Dev\SapphireTest;
use YouWillLikeIT\CareDispatch\Routing\InsertionHeuristicSolver;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingJob;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingProblem;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingSolution;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingVehicle;

class InsertionHeuristicSolverTest extends SapphireTest
{
    protected $usesDatabase = false;

    /** 0 = depot, 1..4 patients on a line, 10 minutes apart. */
    private function matrix(): array
    {
        $m = [];
        for ($i = 0; $i <= 4; $i++) {
            for ($j = 0; $j <= 4; $j++) {
                $m[$i][$j] = abs($i - $j) * 600;
            }
        }

        return $m;
    }

    public function testRespectsQualificationExclusionAndPinnedTime(): void
    {
        $h = 3600;
        $jobs = [
            new RoutingJob(1, 1, 1200, 7 * $h, 9 * $h, 3),                    // Fachkraft only
            new RoutingJob(2, 2, 1200, 7 * $h, 9 * $h, 1, [20]),              // only vehicle 20 allowed
            new RoutingJob(3, 3, 1200, 7 * $h, 12 * $h, 1),
            new RoutingJob(4, 4, 1800, 10 * $h, 10 * $h, 1, null, [], 20),    // pinned 10:00 on vehicle 20
        ];
        $vehicles = [
            new RoutingVehicle(10, 0, 0, 6 * $h, 14 * $h, 3, true),
            new RoutingVehicle(20, 0, 0, 6 * $h, 14 * $h, 1, true),
            new RoutingVehicle(30, 0, 0, 6 * $h, 14 * $h, 0, false),          // Azubi
        ];
        $solution = InsertionHeuristicSolver::create()->solve(new RoutingProblem($this->matrix(), $jobs, $vehicles));

        $byVehicle = [];
        foreach ($solution->routes as $vehicleId => $stops) {
            foreach ($stops as $stop) {
                $byVehicle[$stop->jobId] = [$vehicleId, $stop->start];
            }
        }
        $this->assertSame([], $solution->unassigned);
        $this->assertSame(10, $byVehicle[1][0], 'Fachkraft job must go to the Fachkraft');
        $this->assertSame(20, $byVehicle[2][0], 'Exclusion list must be respected');
        $this->assertSame([20, 10 * $h], $byVehicle[4], 'Pinned job keeps vehicle and time');
        $this->assertArrayNotHasKey(30, $solution->routes, 'Azubi never gets primary jobs');
    }

    public function testReportsJobsWithoutQualifiedVehicle(): void
    {
        $jobs = [new RoutingJob(1, 1, 600, 0, 86399, 3)];
        $vehicles = [new RoutingVehicle(1, 0, 0, 0, 86399, 1, true)];
        $solution = InsertionHeuristicSolver::create()->solve(new RoutingProblem($this->matrix(), $jobs, $vehicles));

        $this->assertSame([1 => RoutingSolution::REASON_NO_QUALIFIED], $solution->unassigned);
    }
}
