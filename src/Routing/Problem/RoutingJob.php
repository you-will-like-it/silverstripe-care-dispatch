<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing\Problem;

/**
 * A visit to place. Times are seconds since midnight of the planning day.
 */
final readonly class RoutingJob
{
    /**
     * @param int[]|null $allowedVehicleIds null = every vehicle with sufficient rank
     * @param int[]      $preferredVehicleIds soft preference (Bezugspflege)
     */
    public function __construct(
        public int $id,
        public int $location,
        public int $serviceSeconds,
        public int $windowStart,
        public int $windowEnd,
        public int $requiredRank,
        public ?array $allowedVehicleIds = null,
        public array $preferredVehicleIds = [],
        public ?int $pinnedVehicleId = null,
    ) {
    }

    public function isPinned(): bool
    {
        return $this->pinnedVehicleId !== null;
    }

    public function allows(RoutingVehicle $vehicle): bool
    {
        if ($this->pinnedVehicleId !== null) {
            return $vehicle->id === $this->pinnedVehicleId;
        }
        // Rank 0 = companion (Begleitung): anyone may join, including Azubis.
        if ($this->requiredRank > 0 && (!$vehicle->canBePrimary || $vehicle->rank < $this->requiredRank)) {
            return false;
        }

        return $this->allowedVehicleIds === null || in_array($vehicle->id, $this->allowedVehicleIds, true);
    }
}
