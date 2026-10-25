<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;

/**
 * Automatically logs create/update/delete of the owner into the audit trail.
 * Applied via YAML to every care dispatch model (see _config/config.yml).
 *
 * @extends Extension<DataObject>
 */
class AuditableExtension extends Extension
{
    /** @var array<int, bool> spl_object_id => was new before write */
    private array $pendingCreate = [];

    /** @var array<int, array{id:int, snapshot:array<string,mixed>}> */
    private array $pendingDelete = [];

    public function __construct(private ?AuditLogger $logger = null)
    {
    }

    protected function onBeforeWrite(): void
    {
        $this->pendingCreate[spl_object_id($this->getOwner())] = !$this->getOwner()->isInDB();
    }

    protected function onAfterWrite(): void
    {
        $owner = $this->getOwner();
        $key = spl_object_id($owner);
        $isCreate = $this->pendingCreate[$key] ?? false;
        unset($this->pendingCreate[$key]);

        $logger = $this->logger();
        if ($isCreate) {
            $logger->recordChange($owner, AuditEntry::ACTION_CREATE, $this->snapshot($owner));

            return;
        }

        $changes = [];
        $ignored = $logger->ignoredFields();
        foreach ($owner->getChangedFields(true, DataObject::CHANGE_VALUE) as $field => $change) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $changes[$field] = [$change['before'] ?? null, $change['after'] ?? null];
        }
        $logger->recordChange($owner, AuditEntry::ACTION_UPDATE, $changes);
    }

    protected function onBeforeDelete(): void
    {
        $owner = $this->getOwner();
        $this->pendingDelete[spl_object_id($owner)] = [
            'id' => (int) $owner->ID,
            'snapshot' => $this->snapshot($owner),
        ];
    }

    protected function onAfterDelete(): void
    {
        $owner = $this->getOwner();
        $key = spl_object_id($owner);
        $pending = $this->pendingDelete[$key] ?? null;
        unset($this->pendingDelete[$key]);
        if ($pending === null) {
            return;
        }
        // The ORM keeps the ID on the instance until after onAfterDelete in CMS 6, but don't rely on it.
        $restoreID = $owner->ID;
        $owner->ID = $pending['id'];
        try {
            $this->logger()->recordChange($owner, AuditEntry::ACTION_DELETE, $pending['snapshot']);
        } finally {
            $owner->ID = $restoreID;
        }
    }

    /** @return array<string, array{0:mixed,1:mixed}> */
    private function snapshot(DataObject $owner): array
    {
        $ignored = $this->logger()->ignoredFields();
        $schema = DataObject::getSchema();
        $out = [];
        foreach (array_keys($schema->databaseFields($owner::class, false)) as $field) {
            if (in_array($field, $ignored, true) || $field === 'ID') {
                continue;
            }
            $value = $owner->getField($field);
            if ($value === null || $value === '') {
                continue;
            }
            $out[$field] = [null, $value];
        }

        return $out;
    }

    private function logger(): AuditLogger
    {
        return $this->logger ??= AuditLogger::singleton();
    }
}
