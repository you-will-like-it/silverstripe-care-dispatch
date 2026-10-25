<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use LogicException;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Append-only audit trail for all care dispatch records and domain events.
 *
 * Records are immutable: canEdit/canDelete are always false and a second write throws.
 *
 * @property string $SubjectClass
 * @property int    $SubjectID
 * @property int    $PatientID
 * @property string $Action
 * @property string $Changes   JSON: {"Field": [old, new]} for updates, snapshot for create/delete, payload for events
 * @property string $Reason
 * @property int    $MemberID
 * @property string $MemberName
 * @property string $IPAddress
 * @method Member Member()
 * @method Patient Patient()
 */
class AuditEntry extends DataObject
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_READ = 'read';

    private static string $table_name = 'CareDispatch_AuditEntry';

    private static string $singular_name = 'Protokolleintrag';

    private static string $plural_name = 'Änderungsprotokoll';

    private static array $db = [
        'SubjectClass' => 'Varchar(255)',
        'SubjectID' => 'Int',
        'Action' => 'Varchar(50)',
        'Changes' => 'Text',
        'Reason' => 'Text',
        // Snapshot so the entry stays readable after the account is deleted.
        'MemberName' => 'Varchar(255)',
        'IPAddress' => 'Varchar(45)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'Patient' => Patient::class,
    ];

    private static array $indexes = [
        'Subject' => ['type' => 'index', 'columns' => ['SubjectClass', 'SubjectID']],
        'PatientCreated' => ['type' => 'index', 'columns' => ['PatientID', 'Created']],
        'Action' => true,
        'Created' => true,
    ];

    private static array $summary_fields = [
        'Created.Nice' => 'Zeitpunkt',
        'MemberName' => 'Benutzer',
        'Action' => 'Aktion',
        'SubjectLabel' => 'Objekt',
        'Patient.Title' => 'Patient',
        'Reason' => 'Grund',
    ];

    private static array $searchable_fields = [
        'Action',
        'SubjectClass',
        'MemberName',
        'PatientID',
    ];

    private static string $default_sort = '"Created" DESC, "ID" DESC';

    public function getSubjectLabel(): string
    {
        $short = substr((string) strrchr('\\' . $this->SubjectClass, '\\'), 1);

        return sprintf('%s #%d', $short, $this->SubjectID);
    }

    /** @return array<string, mixed> */
    public function getChangesArray(): array
    {
        $decoded = json_decode((string) $this->Changes, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function canView($member = null): bool
    {
        return (bool) Permission::check(CareDispatchPermissions::AUDIT, 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        // Only created programmatically by AuditLogger.
        return false;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $pretty = json_encode($this->getChangesArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fields->replaceField(
            'Changes',
            LiteralField::create('ChangesPretty', '<pre class="care-dispatch-audit">' . htmlspecialchars((string) $pretty) . '</pre>')
        );

        return $fields;
    }

    protected function onBeforeWrite(): void
    {
        if ($this->isInDB()) {
            throw new LogicException('Audit entries are immutable.');
        }
        parent::onBeforeWrite();
    }

    protected function onBeforeDelete(): void
    {
        throw new LogicException('Audit entries cannot be deleted.');
    }
}
