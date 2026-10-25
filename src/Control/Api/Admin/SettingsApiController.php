<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use Throwable;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Model\CareDispatchSettings;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Routing\GeoPoint;
use YouWillLikeIT\CareDispatch\Routing\NullGeocoder;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\Transaction;

/**
 * /api/admin/settings – Einstellungen (read: back office, write: SETTINGS).
 */
class SettingsApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET get' => 'getSettings',
        'POST save' => 'saveSettings',
    ];

    private static array $allowed_actions = ['getSettings', 'saveSettings'];

    public function getSettings(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => ['settings' => $this->present(CareDispatchSettings::current())]);
    }

    /**
     * POST save {companyName, depotStreet, depotPostalCode, depotCity, depotLat?, depotLng?,
     *            timelineStart, timelineEnd, lateAfterMinutes, logReadAccess}
     * Without lat/lng the depot address is geocoded (if a geocoder is configured).
     */
    public function saveSettings(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::SETTINGS);
            $body = $this->body();
            $settings = CareDispatchSettings::current();
            $notice = null;
            Transaction::run(function () use ($settings, $body, &$notice): void {
                $map = [
                    'companyName' => 'CompanyName', 'depotStreet' => 'DepotStreet', 'depotPostalCode' => 'DepotPostalCode',
                    'depotCity' => 'DepotCity', 'timelineStart' => 'TimelineStart', 'timelineEnd' => 'TimelineEnd',
                ];
                $addressChanged = false;
                foreach ($map as $key => $field) {
                    if ($this->has($body, $key)) {
                        $value = $this->stringParam($body, $key, 255);
                        $addressChanged = $addressChanged || (str_starts_with($field, 'Depot') && $value !== (string) $settings->$field);
                        $settings->$field = $value;
                    }
                }
                if ($this->has($body, 'lateAfterMinutes')) {
                    $settings->LateAfterMinutes = (int) $body['lateAfterMinutes'];
                }
                if ($this->has($body, 'logReadAccess')) {
                    $settings->LogReadAccess = $this->boolParam($body, 'logReadAccess');
                }

                $lat = $body['depotLat'] ?? null;
                $lng = $body['depotLng'] ?? null;
                if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                    $point = new GeoPoint((float) $lat, (float) $lng);
                    $settings->DepotLat = $point->lat;
                    $settings->DepotLng = $point->lng;
                } elseif (($addressChanged || $this->boolParam($body, 'geocode')) && trim((string) $settings->DepotStreet) !== '') {
                    $point = $this->geocode($settings);
                    $settings->DepotLat = $point?->lat ?? 0.0;
                    $settings->DepotLng = $point?->lng ?? 0.0;
                    if ($point === null) {
                        $notice = 'Adresse konnte nicht automatisch verortet werden. Bitte Koordinaten manuell eintragen.';
                    }
                }
                $settings->write();
            });

            return ['settings' => $this->present($settings), 'notice' => $notice];
        });
    }

    private function geocode(CareDispatchSettings $settings): ?GeoPoint
    {
        $geocoder = Patient::geocoder();
        if ($geocoder === null || $geocoder instanceof NullGeocoder) {
            return null;
        }
        try {
            return $geocoder->geocode((string) $settings->DepotStreet, (string) $settings->DepotPostalCode, (string) $settings->DepotCity, 'DE');
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->warning('CareDispatch: depot geocoding failed: ' . $e->getMessage());
            throw new DomainRuleException('GEOCODE_FAILED', 'Geokodierung derzeit nicht erreichbar. Bitte Koordinaten manuell eintragen.');
        }
    }

    /** @return array<string, mixed> */
    private function present(CareDispatchSettings $settings): array
    {
        $depot = $settings->getDepot();

        return [
            'companyName' => (string) $settings->CompanyName,
            'depotStreet' => (string) $settings->DepotStreet,
            'depotPostalCode' => (string) $settings->DepotPostalCode,
            'depotCity' => (string) $settings->DepotCity,
            'depotLat' => $depot?->lat,
            'depotLng' => $depot?->lng,
            'timelineStart' => (string) ($settings->TimelineStart ?: '05:00'),
            'timelineEnd' => (string) ($settings->TimelineEnd ?: '23:00'),
            'lateAfterMinutes' => (int) $settings->LateAfterMinutes,
            'logReadAccess' => (bool) $settings->LogReadAccess,
            'geocoderEnabled' => !(Patient::geocoder() instanceof NullGeocoder) && Patient::geocoder() !== null,
            'canEdit' => P::check(P::SETTINGS),
        ];
    }
}
