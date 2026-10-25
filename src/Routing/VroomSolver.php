<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Routing\Problem\RouteStop;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingProblem;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingSolution;

/**
 * VRPTW via a VROOM server (https://github.com/VROOM-Project/vroom), using our own duration
 * matrix (so VROOM needs no routing engine of its own).
 *
 * Constraint mapping:
 * - qualification rank r: vehicle skills {1..r}, job requires {rank}; Azubi vehicles get no rank skills;
 *   companion jobs (rank 0) require no rank skill
 * - pinned job: unique skill per vehicle (100000 + vehicle index)
 * - allowed-vehicle list (patient exclusions): unique skill per job (200000 + job index),
 *   granted to every allowed vehicle
 * - preferences (Bezugspflege) are not expressible in VROOM and are ignored here
 *
 * Env: CARE_DISPATCH_VROOM_URL (e.g. http://vroom:3000)
 */
class VroomSolver implements RouteSolverInterface
{
    use Configurable;
    use Injectable;

    private static string $base_url = '';

    private static float $timeout = 20.0;

    private ?ClientInterface $client = null;

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '';
    }

    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function name(): string
    {
        return 'vroom';
    }

    public function solve(RoutingProblem $problem): RoutingSolution
    {
        $payload = $this->buildPayload($problem);
        $response = $this->client()->request('POST', '', [
            'json' => $payload,
            'http_errors' => true,
        ]);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || (int) ($data['code'] ?? -1) !== 0) {
            throw new RuntimeException('VROOM error: ' . (is_array($data) ? (string) ($data['error'] ?? 'unknown') : 'invalid response'));
        }

        $solution = new RoutingSolution($this->name());
        $vehicleIds = array_values(array_map(static fn ($v): int => $v->id, $problem->vehicles));
        $jobIds = array_values(array_map(static fn ($j): int => $j->id, $problem->jobs));

        foreach ($data['routes'] ?? [] as $route) {
            $vehicleId = $vehicleIds[(int) $route['vehicle'] - 1] ?? null;
            if ($vehicleId === null) {
                continue;
            }
            $stops = [];
            $previousLocation = null;
            foreach ($route['steps'] ?? [] as $step) {
                $type = (string) ($step['type'] ?? '');
                if ($type === 'start') {
                    $previousLocation = (int) ($step['location_index'] ?? 0);
                    continue;
                }
                if ($type !== 'job') {
                    continue;
                }
                $jobId = $jobIds[(int) $step['id'] - 1] ?? null;
                if ($jobId === null) {
                    continue;
                }
                $arrival = (int) $step['arrival'];
                $start = $arrival + (int) ($step['waiting_time'] ?? 0);
                $end = $start + (int) ($step['service'] ?? 0);
                $location = (int) ($step['location_index'] ?? 0);
                $travel = $previousLocation === null ? 0 : $problem->travel($previousLocation, $location);
                $stops[] = new RouteStop($jobId, $arrival, $start, $end, $travel);
                $previousLocation = $location;
            }
            if ($stops !== []) {
                $solution->routes[$vehicleId] = $stops;
            }
        }
        foreach ($data['unassigned'] ?? [] as $item) {
            $jobId = $jobIds[(int) $item['id'] - 1] ?? null;
            if ($jobId !== null) {
                $solution->unassigned[$jobId] = RoutingSolution::REASON_NO_SLOT;
            }
        }

        return $solution;
    }

    /** @return array<string, mixed> */
    public function buildPayload(RoutingProblem $problem): array
    {
        $vehicleIndex = [];
        $vehicleSkills = [];
        foreach (array_values($problem->vehicles) as $i => $vehicle) {
            $vehicleIndex[$vehicle->id] = $i;
            $skills = $vehicle->canBePrimary ? range(1, max(1, $vehicle->rank)) : [];
            $skills[] = 100000 + $i;
            $vehicleSkills[$i] = $skills;
        }

        $jobs = [];
        foreach (array_values($problem->jobs) as $j => $job) {
            $skills = [];
            if ($job->pinnedVehicleId !== null) {
                $skills[] = 100000 + ($vehicleIndex[$job->pinnedVehicleId] ?? -1);
            } else {
                if ($job->requiredRank > 0) {
                    $skills[] = $job->requiredRank;
                }
                if ($job->allowedVehicleIds !== null) {
                    $token = 200000 + $j;
                    $skills[] = $token;
                    foreach ($job->allowedVehicleIds as $allowedId) {
                        if (isset($vehicleIndex[$allowedId])) {
                            $vehicleSkills[$vehicleIndex[$allowedId]][] = $token;
                        }
                    }
                }
            }
            $jobs[] = [
                'id' => $j + 1,
                'location_index' => $job->location,
                'service' => $job->serviceSeconds,
                'time_windows' => [[$job->windowStart, max($job->windowStart, $job->windowEnd)]],
                'skills' => $skills,
                // Pinned (already planned) visits must never be dropped.
                'priority' => $job->isPinned() ? 100 : 10,
            ];
        }

        $vehicles = [];
        foreach (array_values($problem->vehicles) as $i => $vehicle) {
            $vehicles[] = [
                'id' => $i + 1,
                'start_index' => $vehicle->startLocation,
                'end_index' => $vehicle->endLocation,
                'time_window' => [$vehicle->windowStart, $vehicle->windowEnd],
                'skills' => array_values(array_unique($vehicleSkills[$i])),
            ];
        }

        return [
            'vehicles' => $vehicles,
            'jobs' => $jobs,
            'matrices' => ['car' => ['durations' => $problem->durations]],
            'options' => ['g' => false],
        ];
    }

    private static function baseUrl(): string
    {
        return rtrim((string) (Environment::getEnv('CARE_DISPATCH_VROOM_URL') ?: static::config()->get('base_url')), '/');
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'base_uri' => self::baseUrl() . '/',
            'timeout' => (float) static::config()->get('timeout'),
        ]);
    }
}
