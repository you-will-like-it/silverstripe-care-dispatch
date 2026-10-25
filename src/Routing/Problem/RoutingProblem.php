<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing\Problem;

use InvalidArgumentException;

final readonly class RoutingProblem
{
    /**
     * @param int[][]          $durations travel seconds between location indices
     * @param RoutingJob[]     $jobs
     * @param RoutingVehicle[] $vehicles
     */
    public function __construct(
        public array $durations,
        public array $jobs,
        public array $vehicles,
    ) {
        $n = count($durations);
        foreach ($jobs as $job) {
            if ($job->location < 0 || $job->location >= $n) {
                throw new InvalidArgumentException('Job location index out of range.');
            }
        }
        foreach ($vehicles as $vehicle) {
            if ($vehicle->startLocation >= $n || $vehicle->endLocation >= $n) {
                throw new InvalidArgumentException('Vehicle location index out of range.');
            }
        }
    }

    public function travel(int $from, int $to): int
    {
        return (int) ($this->durations[$from][$to] ?? 0);
    }

    public function job(int $id): ?RoutingJob
    {
        foreach ($this->jobs as $job) {
            if ($job->id === $id) {
                return $job;
            }
        }

        return null;
    }
}
