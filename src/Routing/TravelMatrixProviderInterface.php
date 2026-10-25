<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

interface TravelMatrixProviderInterface
{
    /**
     * Driving durations in seconds between all points: $matrix[$from][$to].
     *
     * @param GeoPoint[] $points list (0-indexed)
     * @return int[][]
     */
    public function durations(array $points): array;

    /** Short identifier for diagnostics, e.g. "osrm" or "haversine". */
    public function name(): string;
}
