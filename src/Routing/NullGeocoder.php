<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

/** Geocoding disabled: coordinates must be maintained manually or imported. */
final class NullGeocoder implements GeocoderInterface
{
    public function geocode(string $street, string $postalCode, string $city, string $countryCode): ?GeoPoint
    {
        return null;
    }
}
