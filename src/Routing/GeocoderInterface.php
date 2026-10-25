<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

interface GeocoderInterface
{
    /** Returns null when the address cannot be resolved. May throw on transport errors. */
    public function geocode(string $street, string $postalCode, string $city, string $countryCode): ?GeoPoint;
}
