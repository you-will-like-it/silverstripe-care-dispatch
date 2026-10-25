<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Routing\Problem\RouteStop;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingJob;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingProblem;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingSolution;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingVehicle;

/**
 * Pure-PHP VRPTW heuristic: cheapest feasible insertion (tightest time windows first)
 * followed by relocate local search. Works without any routing server and is the fallback
 * when VROOM is not configured or not reachable.
 *
 * Pinned jobs (fixed visits, double visits) keep their vehicle; their time-window violations
 * are tolerated so that existing plans never make the whole route infeasible.
 */
class InsertionHeuristicSolver implements RouteSolverInterface
{
    use Configurable;
    use Injectable;

    /** Weight of waiting time relative to driving time. */
    private static float $waiting_weight = 0.2;

    /** Cost added when a job with preferences is served by a non-preferred caregiver. */
    private static int $preference_penalty_seconds = 900;

    private static int $max_improvement_passes = 6;

    private RoutingProblem $problem;

    /** @var array<int, RoutingJob> */
    private array $jobs = [];

    /** @var array<int, RoutingVehicle> */
    private array $vehicles = [];

    public function name(): string
    {
        return 'heuristic';
    }

    public function solve(RoutingProblem $problem): RoutingSolution
    {
        $this->problem = $problem;
        $this->jobs = [];
        foreach ($problem->jobs as $job) {
            $this->jobs[$job->id] = $job;
        }
        $this->vehicles = [];
        foreach ($problem->vehicles as $vehicle) {
            $this->vehicles[$vehicle->id] = $vehicle;
        }

        /** @var array<int, int[]> $routes vehicleId => job ids */
        $routes = array_fill_keys(array_keys($this->vehicles), []);
        $unassigned = [];

        // 1. Pinned jobs in time order.
        $pinned = array_filter($this->jobs, static fn (RoutingJob $j): bool => $j->isPinned());
        usort($pinned, static fn (RoutingJob $a, RoutingJob $b): int => $a->windowStart <=> $b->windowStart);
        foreach ($pinned as $job) {
            if (!isset($routes[$job->pinnedVehicleId])) {
                $unassigned[$job->id] = RoutingSolution::REASON_NO_QUALIFIED;
                continue;
            }
            $routes[$job->pinnedVehicleId][] = $job->id;
        }

        // 2. Free jobs, most constrained first: fewest qualified caregivers (e.g. Fachkraft-only),
        //    then tightest time window, then earliest.
        $free = array_filter($this->jobs, static fn (RoutingJob $j): bool => !$j->isPinned());
        $candidates = [];
        foreach ($free as $job) {
            $candidates[$job->id] = count(array_filter($this->vehicles, static fn (RoutingVehicle $v): bool => $job->allows($v)));
        }
        usort($free, static fn (RoutingJob $a, RoutingJob $b): int => [$candidates[$a->id], $a->windowEnd - $a->windowStart, $a->windowStart, $a->id]
            <=> [$candidates[$b->id], $b->windowEnd - $b->windowStart, $b->windowStart, $b->id]);

        $costs = [];
        foreach ($routes as $vehicleId => $route) {
            // Routes with only pinned jobs are always "feasible" (violations tolerated).
            $costs[$vehicleId] = $this->simulate($vehicleId, $route)['cost'] ?? 0;
        }

        foreach ($free as $job) {
            $qualified = array_filter($this->vehicles, static fn (RoutingVehicle $v): bool => $job->allows($v));
            if ($qualified === []) {
                $unassigned[$job->id] = RoutingSolution::REASON_NO_QUALIFIED;
                continue;
            }
            $best = $this->bestInsertion($job, $routes, $costs);
            if ($best === null) {
                $unassigned[$job->id] = RoutingSolution::REASON_NO_SLOT;
                continue;
            }
            [$vehicleId, $position, $cost] = $best;
            array_splice($routes[$vehicleId], $position, 0, [$job->id]);
            $costs[$vehicleId] = $cost;
        }

        // 3. Relocate local search.
        $passes = (int) static::config()->get('max_improvement_passes');
        for ($pass = 0; $pass < $passes; $pass++) {
            if (!$this->improve($routes, $costs)) {
                break;
            }
        }

        // 4. Try again to place unassigned free jobs after improvement.
        foreach (array_keys($unassigned) as $jobId) {
            $job = $this->jobs[$jobId];
            if ($job->isPinned() || $unassigned[$jobId] === RoutingSolution::REASON_NO_QUALIFIED) {
                continue;
            }
            $best = $this->bestInsertion($job, $routes, $costs);
            if ($best !== null) {
                [$vehicleId, $position, $cost] = $best;
                array_splice($routes[$vehicleId], $position, 0, [$jobId]);
                $costs[$vehicleId] = $cost;
                unset($unassigned[$jobId]);
            }
        }

        $solution = new RoutingSolution($this->name());
        foreach ($routes as $vehicleId => $route) {
            if ($route === []) {
                continue;
            }
            $solution->routes[$vehicleId] = $this->simulate($vehicleId, $route, true)['stops'] ?? [];
        }
        $solution->unassigned = $unassigned;

        return $solution;
    }

    /**
     * @param array<int, int[]> $routes
     * @param array<int, int>   $costs
     * @return array{0:int,1:int,2:int}|null [vehicleId, position, newRouteCost]
     */
    private function bestInsertion(RoutingJob $job, array $routes, array $costs): ?array
    {
        $best = null;
        $bestDelta = PHP_INT_MAX;
        foreach ($this->vehicles as $vehicleId => $vehicle) {
            if (!$job->allows($vehicle)) {
                continue;
            }
            $route = $routes[$vehicleId];
            for ($pos = 0, $len = count($route); $pos <= $len; $pos++) {
                $candidate = $route;
                array_splice($candidate, $pos, 0, [$job->id]);
                $sim = $this->simulate($vehicleId, $candidate);
                if ($sim === null) {
                    continue;
                }
                $delta = $sim['cost'] - $costs[$vehicleId];
                if ($delta < $bestDelta) {
                    $bestDelta = $delta;
                    $best = [$vehicleId, $pos, $sim['cost']];
                }
            }
        }

        return $best;
    }

    /**
     * @param array<int, int[]> $routes
     * @param array<int, int>   $costs
     */
    private function improve(array &$routes, array &$costs): bool
    {
        $improved = false;
        foreach (array_keys($routes) as $vehicleId) {
            foreach ($routes[$vehicleId] as $index => $jobId) {
                $job = $this->jobs[$jobId];
                if ($job->isPinned()) {
                    continue;
                }
                $without = $routes[$vehicleId];
                array_splice($without, $index, 1);
                $withoutSim = $this->simulate($vehicleId, $without);
                if ($withoutSim === null) {
                    continue;
                }
                $trialRoutes = $routes;
                $trialRoutes[$vehicleId] = $without;
                $trialCosts = $costs;
                $trialCosts[$vehicleId] = $withoutSim['cost'];
                $best = $this->bestInsertion($job, $trialRoutes, $trialCosts);
                if ($best === null) {
                    continue;
                }
                [$targetVehicle, $position, $newCost] = $best;
                // $newCost is the full cost of the target route after insertion.
                if ($targetVehicle === $vehicleId) {
                    $before = $costs[$vehicleId];
                    $after = $newCost;
                } else {
                    $before = $costs[$vehicleId] + $costs[$targetVehicle];
                    $after = $withoutSim['cost'] + $newCost;
                }
                if ($after + 1 < $before) {
                    $routes[$vehicleId] = $without;
                    $costs[$vehicleId] = $withoutSim['cost'];
                    array_splice($routes[$targetVehicle], $position, 0, [$jobId]);
                    $costs[$targetVehicle] = $newCost;
                    $improved = true;

                    // Indices of the current route changed; continue with the next vehicle.
                    continue 2;
                }
            }
        }

        return $improved;
    }

    /**
     * Forward simulation of a route.
     *
     * @param int[] $route job ids
     * @return array{cost:int, stops:RouteStop[]}|null null if infeasible
     */
    private function simulate(int $vehicleId, array $route, bool $withStops = false): ?array
    {
        $vehicle = $this->vehicles[$vehicleId];
        $waitWeight = (float) static::config()->get('waiting_weight');
        $penalty = (int) static::config()->get('preference_penalty_seconds');

        $time = $vehicle->windowStart;
        $location = $vehicle->startLocation;
        $travelTotal = 0;
        $waitTotal = 0;
        $penaltyTotal = 0;
        $hasFree = false;
        $stops = [];

        foreach ($route as $jobId) {
            $job = $this->jobs[$jobId];
            $travel = $this->problem->travel($location, $job->location);
            $arrival = $time + $travel;
            $start = max($arrival, $job->windowStart);
            if ($job->isPinned()) {
                // A free job must never push a fixed visit past its time; lateness caused by
                // the existing (pinned-only) plan is tolerated.
                if ($hasFree && $start > $job->windowEnd) {
                    return null;
                }
            } else {
                $hasFree = true;
                if ($start > $job->windowEnd) {
                    return null;
                }
                if ($job->preferredVehicleIds !== [] && !in_array($vehicleId, $job->preferredVehicleIds, true)) {
                    $penaltyTotal += $penalty;
                }
            }
            $waitTotal += $start - $arrival;
            $travelTotal += $travel;
            $end = $start + $job->serviceSeconds;
            if ($withStops) {
                $stops[] = new RouteStop($jobId, $arrival, $start, $end, $travel);
            }
            $time = $end;
            $location = $job->location;
        }
        $travelHome = $route === [] ? 0 : $this->problem->travel($location, $vehicle->endLocation);
        $finish = $time + $travelHome;
        if ($hasFree && $finish > $vehicle->windowEnd) {
            return null;
        }
        $travelTotal += $travelHome;

        return [
            'cost' => (int) round($travelTotal + $waitWeight * $waitTotal + $penaltyTotal),
            'stops' => $stops,
        ];
    }
}
