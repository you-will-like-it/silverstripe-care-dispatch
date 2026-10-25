<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing\Problem;

final class RoutingSolution
{
    public const REASON_NO_QUALIFIED = 'NO_QUALIFIED_CAREGIVER';
    public const REASON_NO_SLOT = 'NO_FEASIBLE_SLOT';

    /**
     * @param array<int, RouteStop[]> $routes vehicleId => ordered stops
     * @param array<int, string>      $unassigned jobId => reason
     */
    public function __construct(
        public readonly string $solver,
        public array $routes = [],
        public array $unassigned = [],
    ) {
    }

    public function totalTravelSeconds(): int
    {
        $sum = 0;
        foreach ($this->routes as $stops) {
            foreach ($stops as $stop) {
                $sum += $stop->travelSeconds;
            }
        }

        return $sum;
    }
}
