<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Driving durations from an OSRM server (/table service).
 *
 * Only coordinates are transmitted. Self-host OSRM (see docker/routing) so that no
 * location data leaves your infrastructure. Unroutable pairs fall back to the haversine estimate.
 *
 * Env: CARE_DISPATCH_OSRM_URL (e.g. http://osrm:5000)
 */
class OsrmMatrixProvider implements TravelMatrixProviderInterface
{
    use Configurable;
    use Injectable;

    private static string $base_url = '';

    private static string $profile = 'driving';

    private static float $timeout = 10.0;

    /** Must not exceed the server's --max-table-size. */
    private static int $max_locations = 500;

    /** Added per leg (parking, walking to the door). */
    private static int $leg_overhead_seconds = 120;

    private ?ClientInterface $client = null;

    public function __construct(private ?HaversineMatrixProvider $fallback = null)
    {
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '';
    }

    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function durations(array $points): array
    {
        $n = count($points);
        if ($n === 0) {
            return [];
        }
        if ($n > (int) static::config()->get('max_locations')) {
            throw new RuntimeException(sprintf('OSRM table limit exceeded (%d locations).', $n));
        }
        $coords = implode(';', array_map(static fn (GeoPoint $p): string => sprintf('%.6F,%.6F', $p->lng, $p->lat), $points));
        $path = sprintf('table/v1/%s/%s', static::config()->get('profile'), $coords);
        $response = $this->client()->request('GET', $path, [
            'query' => ['annotations' => 'duration'],
            'http_errors' => true,
        ]);
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || ($data['code'] ?? '') !== 'Ok' || !isset($data['durations']) || !is_array($data['durations'])) {
            throw new RuntimeException('Unexpected OSRM response: ' . substr((string) $response->getBody(), 0, 200));
        }

        $fallback = null;
        $overhead = max(0, (int) static::config()->get('leg_overhead_seconds'));
        $matrix = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    $matrix[$i][$j] = 0;
                    continue;
                }
                $value = $data['durations'][$i][$j] ?? null;
                if ($value === null) {
                    $fallback ??= ($this->fallback ?? HaversineMatrixProvider::singleton())->durations($points);
                    $matrix[$i][$j] = $fallback[$i][$j];
                    continue;
                }
                $matrix[$i][$j] = (int) round((float) $value) + ($value > 0 ? $overhead : 0);
            }
        }

        return $matrix;
    }

    public function name(): string
    {
        return 'osrm';
    }

    private static function baseUrl(): string
    {
        return rtrim((string) (Environment::getEnv('CARE_DISPATCH_OSRM_URL') ?: static::config()->get('base_url')), '/');
    }

    private function client(): ClientInterface
    {
        return $this->client ??= new Client([
            'base_uri' => self::baseUrl() . '/',
            'timeout' => (float) static::config()->get('timeout'),
        ]);
    }
}
