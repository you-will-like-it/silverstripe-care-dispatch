<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use DateInterval;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\CareDispatchSettings;
use YouWillLikeIT\CareDispatch\Model\Patient;

/**
 * Writes the append-only audit trail.
 *
 * - record changes are logged automatically by AuditableExtension
 * - domain events (SOS, assignment, overrides ...) are logged explicitly via event()
 * - read access is logged via read() only when `log_read_access` is enabled (default false)
 */
class AuditLogger
{
    use Configurable;
    use Injectable;

    /** Log who opened which patient record (Zugriffsprotokoll). */
    private static bool $log_read_access = false;

    /** Suppress repeated read entries of the same member/patient within this window. */
    private static int $read_dedupe_minutes = 15;

    /** Fields never written to the change log. */
    private static array $ignored_fields = ['LastEdited', 'Created', 'ClassName', 'RecordClassName', 'SortOrder'];

    /** Fields whose values are replaced by a marker (never store secrets in the log). */
    private static array $masked_fields = ['Password', 'Salt', 'TempIDHash', 'AutoLoginHash'];

    /** Guards against recursion when audited records write other audited records. */
    private bool $suspended = false;

    /**
     * @param array<string, array{0:mixed,1:mixed}> $changes
     */
    public function recordChange(DataObject $subject, string $action, array $changes): void
    {
        if ($this->suspended || ($action === AuditEntry::ACTION_UPDATE && $changes === [])) {
            return;
        }
        $this->write($subject::class, (int) $subject->ID, $this->resolvePatientID($subject), $action, $changes, '');
    }

    /**
     * Domain event, e.g. 'sos_interrupt', 'visit_assigned', 'force_override'.
     *
     * @param array<string, mixed> $payload
     */
    public function event(DataObject $subject, string $action, array $payload = [], string $reason = ''): void
    {
        $this->write($subject::class, (int) $subject->ID, $this->resolvePatientID($subject), $action, $payload, $reason);
    }

    /**
     * Event not bound to a single record (e.g. day generation, applied route proposal).
     *
     * @param array<string, mixed> $payload
     */
    public function systemEvent(string $source, string $action, array $payload = [], string $reason = ''): void
    {
        $this->write($source, 0, 0, $action, $payload, $reason);
    }

    /** Zugriffsprotokoll: logs that the current member viewed a patient record. No-op unless enabled. */
    public function read(int $patientID, string $context): void
    {
        if ($patientID <= 0 || !$this->readLoggingEnabled()) {
            return;
        }
        $member = Security::getCurrentUser();
        $since = Clock::now()
            ->sub(new DateInterval('PT' . max(0, (int) static::config()->get('read_dedupe_minutes')) . 'M'))
            ->format(Clock::DB_DATETIME);
        $recent = AuditEntry::get()->filter([
            'Action' => AuditEntry::ACTION_READ,
            'PatientID' => $patientID,
            'MemberID' => $member?->ID ?? 0,
            'Reason' => $context,
            'Created:GreaterThanOrEqual' => $since,
        ])->exists();
        if ($recent) {
            return;
        }
        $this->write(Patient::class, $patientID, $patientID, AuditEntry::ACTION_READ, [], $context);
    }

    /** Config switch or the frontend setting; either one enables the access log. */
    public function readLoggingEnabled(): bool
    {
        return (bool) static::config()->get('log_read_access') || (bool) CareDispatchSettings::current()->LogReadAccess;
    }

    /** Runs $callback without automatic change logging (used for bulk operations that log one event). */
    public function suspended(callable $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;
        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /** @return string[] */
    public function ignoredFields(): array
    {
        return (array) static::config()->get('ignored_fields');
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function mask(array $values): array
    {
        foreach ((array) static::config()->get('masked_fields') as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '***';
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $class, int $id, int $patientID, string $action, array $data, string $reason): void
    {
        $member = Security::getCurrentUser();
        $entry = AuditEntry::create();
        $entry->SubjectClass = $class;
        $entry->SubjectID = $id;
        $entry->PatientID = $patientID;
        $entry->Action = $action;
        $entry->Changes = json_encode($this->mask($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
        $entry->Reason = $reason;
        $entry->MemberID = $member?->ID ?? 0;
        $entry->MemberName = $member instanceof Member
            ? trim($member->FirstName . ' ' . $member->Surname) . ' <' . $member->Email . '>'
            : (Director::is_cli() ? 'System (CLI)' : 'System');
        $entry->IPAddress = $this->clientIp();
        $entry->write();
    }

    private function resolvePatientID(DataObject $subject): int
    {
        return $subject->hasMethod('getAuditPatientID') ? (int) $subject->getAuditPatientID() : 0;
    }

    private function clientIp(): string
    {
        if (Director::is_cli()) {
            return '';
        }
        $controller = Controller::curr();
        $ip = $controller?->getRequest()?->getIP();

        return is_string($ip) ? substr($ip, 0, 45) : '';
    }
}
