<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Routing\GeoPoint;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\Roles;

/**
 * Company settings (one record per installation), edited in the frontend.
 * Values override YAML/env defaults; empty values fall back to them.
 *
 * @property string $CompanyName
 * @property string $DepotStreet
 * @property string $DepotPostalCode
 * @property string $DepotCity
 * @property float  $DepotLat
 * @property float  $DepotLng
 * @property string $TimelineStart
 * @property string $TimelineEnd
 * @property int    $LateAfterMinutes
 * @property bool   $LogReadAccess
 */
class CareDispatchSettings extends DataObject
{
    private static string $table_name = 'CareDispatch_Settings';

    private static string $singular_name = 'Einstellungen';

    private static array $db = [
        'CompanyName' => 'Varchar(150)',
        'DepotStreet' => 'Varchar(255)',
        'DepotPostalCode' => 'Varchar(10)',
        'DepotCity' => 'Varchar(100)',
        // 0/0 = not set
        'DepotLat' => 'Decimal(10,7)',
        'DepotLng' => 'Decimal(10,7)',
        'TimelineStart' => 'Varchar(5)',
        'TimelineEnd' => 'Varchar(5)',
        'LateAfterMinutes' => 'Int',
        'LogReadAccess' => 'Boolean',
    ];

    private static array $defaults = [
        'TimelineStart' => '05:00',
        'TimelineEnd' => '23:00',
        'LateAfterMinutes' => 15,
        'LogReadAccess' => false,
    ];

    private static ?self $cached = null;

    public static function current(): self
    {
        if (self::$cached !== null && self::$cached->isInDB()) {
            return self::$cached;
        }
        $record = self::get()->sort('ID')->first();
        if ($record === null) {
            $record = self::create();
            $record->write();
        }

        return self::$cached = $record;
    }

    public static function flushCached(): void
    {
        self::$cached = null;
    }

    public function getDepot(): ?GeoPoint
    {
        if ((float) $this->DepotLat === 0.0 && (float) $this->DepotLng === 0.0) {
            return null;
        }

        return new GeoPoint((float) $this->DepotLat, (float) $this->DepotLng);
    }

    public function canView($member = null): bool
    {
        return (bool) Permission::check(array_values(array_diff(CareDispatchPermissions::ALL, [CareDispatchPermissions::MOBILE])), 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return (bool) Permission::check(CareDispatchPermissions::SETTINGS, 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        foreach (['TimelineStart', 'TimelineEnd'] as $field) {
            if ($this->$field && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $this->$field)) {
                $result->addFieldError($field, 'Uhrzeit im Format HH:MM.');
            }
        }
        if ($this->TimelineStart && $this->TimelineEnd
            && Clock::timeToSeconds($this->TimelineEnd) - Clock::timeToSeconds($this->TimelineStart) < 4 * 3600) {
            $result->addFieldError('TimelineEnd', 'Die Zeitleiste muss mindestens 4 Stunden umfassen.');
        }
        if ((int) $this->LateAfterMinutes < 0 || (int) $this->LateAfterMinutes > 240) {
            $result->addFieldError('LateAfterMinutes', 'Wert zwischen 0 und 240 Minuten.');
        }
        if (abs((float) $this->DepotLat) > 90 || abs((float) $this->DepotLng) > 180) {
            $result->addError('Ungültige Koordinaten des Firmenstandorts.');
        }

        return $result;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        self::$cached = $this;
    }

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        if (!self::get()->exists()) {
            self::create()->write();
        }
        Roles::ensureGroups();
    }
}
