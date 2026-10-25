<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use Throwable;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Routing\GeoPoint;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Pflegekraft.
 *
 * @property string $QualificationLevel
 * @property float  $ContractWeeklyHours
 * @property bool   $DrivingLicense
 * @property bool   $IsActive
 * @property string $BoardColor
 * @property string $StartStreet
 * @property string $StartPostalCode
 * @property string $StartCity
 * @property float  $StartLat
 * @property float  $StartLng
 * @property int    $MemberID
 * @method Member Member()
 * @method HasManyList<Shift> Shifts()
 * @method HasManyList<TimeEntry> TimeEntries()
 * @method HasManyList<DailyTour> DailyTours()
 * @method ManyManyList<Patient> PreferredByPatients()
 * @method ManyManyList<Patient> ExcludedByPatients()
 */
class Caregiver extends DataObject
{
    use DispatchPermissions;

    private static string $table_name = 'CareDispatch_Caregiver';

    private static string $singular_name = 'Pflegekraft';

    private static string $plural_name = 'Pflegekräfte';

    private static string $edit_permission = CareDispatchPermissions::MASTERDATA;

    private static array $db = [
        'QualificationLevel' => QualificationLevel::DB_ENUM_ALL,
        'ContractWeeklyHours' => 'Decimal(5,2)',
        'DrivingLicense' => 'Boolean',
        'IsActive' => 'Boolean',
        'BoardColor' => 'Varchar(7)',
        // Optional own start/end point for route optimisation (default: company depot).
        'StartStreet' => 'Varchar(255)',
        'StartPostalCode' => 'Varchar(10)',
        'StartCity' => 'Varchar(100)',
        'StartLat' => 'Decimal(10,7)',
        'StartLng' => 'Decimal(10,7)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $has_many = [
        'Shifts' => Shift::class,
        'TimeEntries' => TimeEntry::class,
        'DailyTours' => DailyTour::class,
    ];

    private static array $belongs_many_many = [
        'PreferredByPatients' => Patient::class . '.PreferredCaregivers',
        'ExcludedByPatients' => Patient::class . '.ExcludedCaregivers',
    ];

    private static array $defaults = [
        'QualificationLevel' => 'Hilfskraft',
        'ContractWeeklyHours' => 39.0,
        'DrivingLicense' => true,
        'IsActive' => true,
    ];

    private static array $indexes = [
        'IsActive' => true,
        'MemberID' => ['type' => 'unique', 'columns' => ['MemberID']],
    ];

    private static array $summary_fields = [
        'Title' => 'Name',
        'QualificationLabel' => 'Qualifikation',
        'ContractWeeklyHours' => 'Std./Woche',
        'DrivingLicense.Nice' => 'Führerschein',
        'IsActive.Nice' => 'Aktiv',
    ];

    private static array $searchable_fields = [
        'Member.FirstName',
        'Member.Surname',
        'QualificationLevel',
        'IsActive',
    ];

    private static string $default_sort = '"IsActive" DESC, "ID" ASC';

    private static array $field_labels = [
        'QualificationLevel' => 'Qualifikation',
        'ContractWeeklyHours' => 'Vertragliche Wochenstunden',
        'DrivingLicense' => 'Führerschein vorhanden',
        'IsActive' => 'Aktiv',
        'BoardColor' => 'Farbe im Board (#RRGGBB)',
        'StartStreet' => 'Startadresse: Straße',
        'StartPostalCode' => 'Startadresse: PLZ',
        'StartCity' => 'Startadresse: Ort',
        'Member' => 'Benutzerkonto',
    ];

    public function getTitle(): string
    {
        $member = $this->Member();
        if ($member->exists()) {
            return trim($member->FirstName . ' ' . $member->Surname) ?: (string) $member->Email;
        }

        return 'Pflegekraft #' . $this->ID;
    }

    public function getQualification(): QualificationLevel
    {
        return QualificationLevel::fromDb($this->QualificationLevel);
    }

    public function getQualificationLabel(): string
    {
        return $this->getQualification()->label();
    }

    public function getStartPoint(): ?GeoPoint
    {
        // 0/0 is the "not geocoded" sentinel (Decimal columns are not nullable).
        if ((float) $this->StartLat === 0.0 && (float) $this->StartLng === 0.0) {
            return null;
        }

        return new GeoPoint((float) $this->StartLat, (float) $this->StartLng);
    }

    public function hasOwnStartAddress(): bool
    {
        return trim((string) $this->StartStreet) !== '' && trim((string) $this->StartCity) !== '';
    }

    public static function forMember(?Member $member): ?self
    {
        if ($member === null || !$member->exists()) {
            return null;
        }

        return self::get()->filter(['MemberID' => $member->ID, 'IsActive' => true])->first();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField(
            'QualificationLevel',
            DropdownField::create('QualificationLevel', $this->fieldLabel('QualificationLevel'), QualificationLevel::options())
        );
        $fields->removeByName(['StartLat', 'StartLng']);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->MemberID) {
            $result->addFieldError('MemberID', 'Jede Pflegekraft benötigt ein Benutzerkonto (für Login und mobile Ansicht).');
        }
        if ((float) $this->ContractWeeklyHours < 0 || (float) $this->ContractWeeklyHours > 60) {
            $result->addFieldError('ContractWeeklyHours', 'Wochenstunden müssen zwischen 0 und 60 liegen.');
        }
        if ($this->BoardColor !== null && $this->BoardColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $this->BoardColor)) {
            $result->addFieldError('BoardColor', 'Farbe im Format #RRGGBB angeben.');
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $addressChanged = $this->isChanged('StartStreet') || $this->isChanged('StartPostalCode') || $this->isChanged('StartCity');
        $explicitCoordinates = ($this->isChanged('StartLat') || $this->isChanged('StartLng')) && $this->getStartPoint() !== null;
        if ($addressChanged && !$explicitCoordinates) {
            $this->StartLat = 0.0;
            $this->StartLng = 0.0;
            if ($this->hasOwnStartAddress()) {
                try {
                    $point = Patient::geocoder()?->geocode(
                        (string) $this->StartStreet,
                        (string) $this->StartPostalCode,
                        (string) $this->StartCity,
                        'DE'
                    );
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->warning(
                        'CareDispatch: geocoding failed for caregiver #' . $this->ID . ': ' . $e->getMessage()
                    );
                    $point = null;
                }
                if ($point !== null) {
                    $this->StartLat = $point->lat;
                    $this->StartLng = $point->lng;
                }
            }
        }
    }
}
