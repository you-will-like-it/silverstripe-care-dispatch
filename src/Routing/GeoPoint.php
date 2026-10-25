<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use InvalidArgumentException;

final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException(sprintf('Invalid coordinates %F, %F.', $lat, $lng));
        }
    }

    /** Great-circle distance in metres. */
    public function distanceTo(self $other): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($other->lat)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($a)));
    }

    /** Stable key for de-duplicating identical locations (~1 m precision). */
    public function key(): string
    {
        return sprintf('%.5f,%.5f', $this->lat, $this->lng);
    }
}
