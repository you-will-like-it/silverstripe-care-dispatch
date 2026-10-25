<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Offline estimate: straight-line distance x detour factor at an average speed,
 * plus a fixed parking/walking overhead per leg. Used when no OSRM server is configured
 * or reachable.
 */
class HaversineMatrixProvider implements TravelMatrixProviderInterface
{
    use Configurable;
    use Injectable;

    /** Average urban driving speed in km/h. */
    private static float $speed_kmh = 28.0;

    /** Road distance / straight-line distance. */
    private static float $detour_factor = 1.35;

    /** Parking + walking to the door, per leg with distance > 0. */
    private static int $leg_overhead_seconds = 180;

    public function durations(array $points): array
    {
        $speed = max(1.0, (float) static::config()->get('speed_kmh')) / 3.6; // m/s
        $detour = max(1.0, (float) static::config()->get('detour_factor'));
        $overhead = max(0, (int) static::config()->get('leg_overhead_seconds'));
        $n = count($points);
        $matrix = array_fill(0, $n, array_fill(0, $n, 0));
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $metres = $points[$i]->distanceTo($points[$j]);
                $seconds = $metres < 1.0 ? 0 : (int) round(($metres * $detour) / $speed) + $overhead;
                $matrix[$i][$j] = $seconds;
                $matrix[$j][$i] = $seconds;
            }
        }

        return $matrix;
    }

    public function name(): string
    {
        return 'haversine';
    }
}
