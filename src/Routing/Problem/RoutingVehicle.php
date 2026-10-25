<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing\Problem;

/**
 * A caregiver available for routing. Id = caregiver ID.
 */
final readonly class RoutingVehicle
{
    public function __construct(
        public int $id,
        public int $startLocation,
        public int $endLocation,
        public int $windowStart,
        public int $windowEnd,
        public int $rank,
        public bool $canBePrimary,
        public ?int $tourId = null,
    ) {
    }
}
