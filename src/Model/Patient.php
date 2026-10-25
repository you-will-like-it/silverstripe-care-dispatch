<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use Throwable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Routing\GeocoderInterface;
use YouWillLikeIT\CareDispatch\Routing\GeoPoint;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * @property string      $FirstName
 * @property string      $Surname
 * @property string      $Street
 * @property string      $PostalCode
 * @property string      $City
 * @property string      $Country
 * @property string      $Phone
 * @property string      $AccessNotes   Zugang: Schlüsselsafe, Klingel, Haustiere ...
 * @property string      $CareNotes     Pflegerelevante Hinweise
 * @property float       $Lat
 * @property float       $Lng
 * @property string|null $GeocodedAt
 * @property bool        $IsActive
 * @method HasManyList<CarePlanItem> CarePlanItems()
 * @method HasManyList<PatientVisit> Visits()
 * @method ManyManyList<Caregiver> PreferredCaregivers()
 * @method ManyManyList<Caregiver> ExcludedCaregivers()
 */
class Patient extends DataObject
{
    use DispatchPermissions;

    private static string $table_name = 'CareDispatch_Patient';

    private static string $singular_name = 'Patient';

    private static string $plural_name = 'Patienten';

    private static string $edit_permission = CareDispatchPermissions::MASTERDATA;

    private static array $db = [
        'FirstName' => 'Varchar(100)',
        'Surname' => 'Varchar(100)',
        'Street' => 'Varchar(255)',
        'PostalCode' => 'Varchar(10)',
        'City' => 'Varchar(100)',
        'Country' => 'Varchar(2)',
        'Phone' => 'Varchar(50)',
        'AccessNotes' => 'Text',
        'CareNotes' => 'Text',
        // 0/0 = not geocoded (Decimal columns are not nullable).
        'Lat' => 'Decimal(10,7)',
        'Lng' => 'Decimal(10,7)',
        'GeocodedAt' => 'Datetime',
        'IsActive' => 'Boolean',
    ];

    private static array $has_many = [
        'CarePlanItems' => CarePlanItem::class,
        'Visits' => PatientVisit::class,
    ];

    private static array $many_many = [
        // Soft: the route solver and the board prefer these caregivers.
        'PreferredCaregivers' => Caregiver::class,
        // Hard: never assignable (manual assignment and solver).
        'ExcludedCaregivers' => Caregiver::class,
    ];

    private static array $cascade_deletes = ['CarePlanItems'];

    private static array $defaults = [
        'Country' => 'DE',
        'IsActive' => true,
    ];

    private static array $indexes = [
        'IsActive' => true,
        'Name' => ['type' => 'index', 'columns' => ['Surname', 'FirstName']],
    ];

    private static array $summary_fields = [
        'Title' => 'Name',
        'AddressLine' => 'Adresse',
        'Phone' => 'Telefon',
        'IsGeocoded.Nice' => 'Geokodiert',
        'IsActive.Nice' => 'Aktiv',
    ];

    private static array $searchable_fields = [
        'Surname',
        'FirstName',
        'City',
        'IsActive',
    ];

    private static array $field_labels = [
        'FirstName' => 'Vorname',
        'Surname' => 'Nachname',
        'Street' => 'Straße und Hausnummer',
        'PostalCode' => 'PLZ',
        'City' => 'Ort',
        'Country' => 'Land (ISO, z. B. DE)',
        'Phone' => 'Telefon',
        'AccessNotes' => 'Zugangshinweise',
        'CareNotes' => 'Pflegehinweise',
        'IsActive' => 'Aktiv',
        'PreferredCaregivers' => 'Bevorzugte Pflegekräfte',
        'ExcludedCaregivers' => 'Ausgeschlossene Pflegekräfte',
    ];

    private static string $default_sort = '"Surname" ASC, "FirstName" ASC';

    public function getTitle(): string
    {
        return trim($this->Surname . ', ' . $this->FirstName, ', ');
    }

    public function getAddressLine(): string
    {
        return trim(sprintf('%s, %s %s', $this->Street, $this->PostalCode, $this->City), ', ');
    }

    public function getGeoPoint(): ?GeoPoint
    {
        if ((float) $this->Lat === 0.0 && (float) $this->Lng === 0.0) {
            return null;
        }

        return new GeoPoint((float) $this->Lat, (float) $this->Lng);
    }

    public function getIsGeocoded(): bool
    {
        return $this->getGeoPoint() !== null;
    }

    public function getAuditPatientID(): int
    {
        return (int) $this->ID;
    }

    /** Resolves the configured geocoder, or null when geocoding is disabled. */
    public static function geocoder(): ?GeocoderInterface
    {
        if (!Injector::inst()->has(GeocoderInterface::class)) {
            return null;
        }

        return Injector::inst()->get(GeocoderInterface::class);
    }

    /** Geocodes the current address; returns true on success. Does not write. */
    public function geocode(): bool
    {
        $this->Lat = 0.0;
        $this->Lng = 0.0;
        $this->GeocodedAt = null;
        if (trim((string) $this->Street) === '' || trim((string) $this->City) === '') {
            return false;
        }
        try {
            $point = self::geocoder()?->geocode(
                (string) $this->Street,
                (string) $this->PostalCode,
                (string) $this->City,
                (string) ($this->Country ?: 'DE')
            );
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'CareDispatch: geocoding failed for patient #' . $this->ID . ': ' . $e->getMessage()
            );
            $point = null;
        }
        if ($point === null) {
            return false;
        }
        $this->Lat = $point->lat;
        $this->Lng = $point->lng;
        $this->GeocodedAt = Clock::nowDb();

        return true;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Lat', 'Lng', 'GeocodedAt', 'Visits', 'CarePlanItems']);
        $fields->addFieldToTab(
            'Root.Main',
            ReadonlyField::create(
                'GeoStatus',
                'Geokodierung',
                $this->getIsGeocoded()
                    ? sprintf('%.6f, %.6f', (float) $this->Lat, (float) $this->Lng)
                    : 'nicht geokodiert – Routenoptimierung ignoriert diesen Patienten'
            )
        );
        if ($this->isInDB()) {
            $fields->addFieldToTab(
                'Root.Pflegeplan',
                GridField::create('CarePlanItems', 'Pflegeplan', $this->CarePlanItems(), GridFieldConfig_RecordEditor::create())
            );
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        foreach (['FirstName', 'Surname', 'Street', 'PostalCode', 'City'] as $field) {
            if (trim((string) $this->$field) === '') {
                $result->addFieldError($field, $this->fieldLabel($field) . ' ist ein Pflichtfeld.');
            }
        }
        if ($this->Country && !preg_match('/^[A-Z]{2}$/', (string) $this->Country)) {
            $result->addFieldError('Country', 'Ländercode im ISO-Format (z. B. DE, AT, CH).');
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->Country = strtoupper((string) ($this->Country ?: 'DE'));
        $addressChanged = !$this->isInDB()
            || $this->isChanged('Street')
            || $this->isChanged('PostalCode')
            || $this->isChanged('City')
            || $this->isChanged('Country');
        if ($addressChanged) {
            $this->geocode();
        }
    }
}
