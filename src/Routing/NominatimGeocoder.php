<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Geocoding via a Nominatim instance (OpenStreetMap).
 *
 * Production: run your own Nominatim (or a provider with a DPA/AVV). The public instance
 * allows max. 1 request/second and requires an identifying User-Agent; this class throttles
 * accordingly. Only the address is sent – never patient names.
 *
 * Env: CARE_DISPATCH_NOMINATIM_URL, CARE_DISPATCH_NOMINATIM_EMAIL
 */
class NominatimGeocoder implements GeocoderInterface
{
    use Configurable;
    use Injectable;

    private static string $base_url = 'https://nominatim.openstreetmap.org';

    private static string $user_agent = 'YouWillLikeIT-CareDispatch/1.0';

    private static float $timeout = 5.0;

    /** Minimum seconds between requests (public Nominatim policy: 1). Set 0 for own instances. */
    private static float $min_interval = 1.0;

    private float $lastRequestAt = 0.0;

    private ?ClientInterface $client = null;

    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function geocode(string $street, string $postalCode, string $city, string $countryCode): ?GeoPoint
    {
        $this->throttle();
        $query = [
            'street' => $street,
            'postalcode' => $postalCode,
            'city' => $city,
            'countrycodes' => strtolower($countryCode),
            'format' => 'jsonv2',
            'limit' => 1,
            'addressdetails' => 0,
        ];
        $email = (string) (Environment::getEnv('CARE_DISPATCH_NOMINATIM_EMAIL') ?: '');
        if ($email !== '') {
            $query['email'] = $email;
        }
        $response = $this->client()->request('GET', 'search', [
            'query' => $query,
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => true,
        ]);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return new GeoPoint((float) $data[0]['lat'], (float) $data[0]['lon']);
    }

    private function client(): ClientInterface
    {
        if ($this->client === null) {
            $base = (string) (Environment::getEnv('CARE_DISPATCH_NOMINATIM_URL') ?: static::config()->get('base_url'));
            $this->client = new Client([
                'base_uri' => rtrim($base, '/') . '/',
                'timeout' => (float) static::config()->get('timeout'),
                'headers' => ['User-Agent' => (string) static::config()->get('user_agent')],
            ]);
        }

        return $this->client;
    }

    private function throttle(): void
    {
        $interval = (float) static::config()->get('min_interval');
        if ($interval <= 0) {
            return;
        }
        $wait = ($this->lastRequestAt + $interval) - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequestAt = microtime(true);
    }
}
