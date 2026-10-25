<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Domain\Weekdays;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\CarePlanItem;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Routing\GeoPoint;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;
use YouWillLikeIT\CareDispatch\Service\Transaction;

/**
 * /api/admin/patients – Stammdaten (MASTERDATA) and Pflegeplan (CAREPLAN).
 */
class PatientsApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET list' => 'listPatients',
        'GET options' => 'options',
        'POST create' => 'createPatient',
        'POST careplan/$ItemID!/delete' => 'deleteCarePlanItem',
        'POST careplan/$ItemID!' => 'updateCarePlanItem',
        'POST $ID!/careplan' => 'createCarePlanItem',
        'POST $ID!/coordinates' => 'coordinates',
        'POST $ID!/delete' => 'deletePatient',
        'GET $ID!' => 'patient',
        'POST $ID!' => 'updatePatient',
    ];

    private static array $allowed_actions = [
        'listPatients', 'options', 'createPatient', 'deleteCarePlanItem', 'updateCarePlanItem',
        'createCarePlanItem', 'coordinates', 'deletePatient', 'patient', 'updatePatient',
    ];

    private static array $dependencies = [
        'audit' => '%$' . AuditLogger::class,
    ];

    public AuditLogger $audit;

    /** GET list?q=&status=active|inactive|all|nogeo */
    public function listPatients(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA, P::CAREPLAN, P::DISPATCH);
            $list = Patient::get();
            $status = (string) ($request->getVar('status') ?: 'active');
            $list = match ($status) {
                'inactive' => $list->filter('IsActive', false),
                'all' => $list,
                'nogeo' => $list->filter(['IsActive' => true, 'Lat' => 0, 'Lng' => 0]),
                default => $list->filter('IsActive', true),
            };
            $q = trim((string) $request->getVar('q'));
            if ($q !== '') {
                $list = $list->filterAny([
                    'Surname:PartialMatch' => $q,
                    'FirstName:PartialMatch' => $q,
                    'Street:PartialMatch' => $q,
                    'City:PartialMatch' => $q,
                ]);
            }
            $counts = [];
            foreach (CarePlanItem::get()->filter('PatientID', $list->column('ID') ?: [0]) as $item) {
                $counts[(int) $item->PatientID] = ($counts[(int) $item->PatientID] ?? 0) + 1;
            }

            return ['patients' => array_map(fn (Patient $p): array => $this->summary($p, $counts[(int) $p->ID] ?? 0), $list->limit(1000)->toArray())];
        });
    }

    /** GET options – active patients with planned minutes per slot (tour template editor). */
    public function options(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::DISPATCH, P::MASTERDATA, P::CAREPLAN);
            $patients = Patient::get()->filter('IsActive', true)->toArray();
            $minutes = [];
            foreach (CarePlanItem::get()->filter('PatientID', array_map(static fn ($p): int => (int) $p->ID, $patients) ?: [0]) as $item) {
                $minutes[(int) $item->PatientID][$item->Slot] = ($minutes[(int) $item->PatientID][$item->Slot] ?? 0) + (int) $item->CareTask()->DurationMinutes;
            }

            return ['patients' => array_map(static fn (Patient $p): array => [
                'id' => (int) $p->ID,
                'name' => $p->getTitle(),
                'address' => $p->getAddressLine(),
                'slots' => (object) ($minutes[(int) $p->ID] ?? []),
            ], $patients)];
        });
    }

    public function patient(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA, P::CAREPLAN, P::DISPATCH);
            $patient = $this->load($request);
            $this->audit->read((int) $patient->ID, 'admin_patient');

            return ['patient' => $this->detail($patient)];
        });
    }

    public function createPatient(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::MASTERDATA);
            $patient = Transaction::run(function (): Patient {
                $patient = Patient::create();
                $this->apply($patient, $this->body());
                $patient->write();

                return $patient;
            });

            return ['patient' => $this->detail($patient)];
        }, 201);
    }

    public function updatePatient(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA);
            $patient = $this->load($request);
            $body = $this->body();
            Transaction::run(function () use ($patient, $body): void {
                $this->apply($patient, $body);
                $patient->write();
                if ($this->has($body, 'preferredCaregiverIds') || $this->has($body, 'excludedCaregiverIds')) {
                    $this->applyWishes($patient, $body);
                }
            });

            return ['patient' => $this->detail($patient)];
        });
    }

    /** Patients with documented visits are kept (Dokumentationspflicht) – deactivate instead. */
    public function deletePatient(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA);
            $patient = $this->load($request);
            if (PatientVisit::get()->filter('PatientID', $patient->ID)->exists()) {
                throw new DomainRuleException('HAS_VISITS', 'Für diesen Patienten gibt es dokumentierte Einsätze. Bitte deaktivieren statt löschen.');
            }
            Transaction::run(static fn () => $patient->delete());

            return ['deleted' => true];
        });
    }

    /** POST $ID/coordinates {lat, lng} sets coordinates manually; {} retries geocoding. */
    public function coordinates(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA);
            $patient = $this->load($request);
            $body = $this->body();
            if ($this->has($body, 'lat') && $this->has($body, 'lng')) {
                $point = new GeoPoint((float) $body['lat'], (float) $body['lng']);
                $patient->Lat = $point->lat;
                $patient->Lng = $point->lng;
                $patient->GeocodedAt = Clock::nowDb();
                $patient->write();
            } elseif ($patient->geocode()) {
                $patient->write();
            } else {
                throw new DomainRuleException('GEOCODE_FAILED', 'Adresse konnte nicht gefunden werden (oder kein Geocoder konfiguriert). Koordinaten bitte manuell eintragen.');
            }

            return ['patient' => $this->detail($patient)];
        });
    }

    public function createCarePlanItem(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::CAREPLAN);
            $patient = $this->load($request);
            $item = CarePlanItem::create();
            $item->PatientID = (int) $patient->ID;
            Transaction::run(function () use ($item): void {
                $this->applyCarePlan($item, $this->body());
                $item->write();
            });

            return ['patient' => $this->detail($patient)];
        }, 201);
    }

    public function updateCarePlanItem(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::CAREPLAN);
            $item = $this->loadItem($request);
            Transaction::run(function () use ($item): void {
                $this->applyCarePlan($item, $this->body());
                $item->write();
            });

            return ['patient' => $this->detail($item->Patient())];
        });
    }

    public function deleteCarePlanItem(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::CAREPLAN);
            $item = $this->loadItem($request);
            $patient = $item->Patient();
            Transaction::run(static fn () => $item->delete());

            return ['patient' => $this->detail($patient)];
        });
    }

    // ------------------------------------------------------------------

    private function load(HTTPRequest $request): Patient
    {
        $id = $this->routeID($request);

        return Patient::get()->byID($id) ?? throw NotFoundException::for('Patient', $id);
    }

    private function loadItem(HTTPRequest $request): CarePlanItem
    {
        $id = $this->routeID($request, 'ItemID');

        return CarePlanItem::get()->byID($id) ?? throw NotFoundException::for('Pflegeplan-Position', $id);
    }

    /** @param array<string, mixed> $body */
    private function apply(Patient $patient, array $body): void
    {
        $map = [
            'firstName' => 'FirstName', 'lastName' => 'Surname', 'street' => 'Street', 'postalCode' => 'PostalCode',
            'city' => 'City', 'country' => 'Country', 'phone' => 'Phone', 'accessNotes' => 'AccessNotes', 'careNotes' => 'CareNotes',
        ];
        foreach ($map as $key => $field) {
            if ($this->has($body, $key)) {
                $patient->$field = $this->stringParam($body, $key, 5000);
            }
        }
        if ($this->has($body, 'isActive')) {
            $patient->IsActive = $this->boolParam($body, 'isActive');
        }
    }

    /** @param array<string, mixed> $body */
    private function applyWishes(Patient $patient, array $body): void
    {
        $preferred = $this->idList($body, 'preferredCaregiverIds');
        $excluded = $this->idList($body, 'excludedCaregiverIds');
        if (array_intersect($preferred, $excluded) !== []) {
            throw BadRequestException::because('Eine Pflegekraft kann nicht gleichzeitig bevorzugt und ausgeschlossen sein.');
        }
        $valid = Caregiver::get()->filter('ID', array_merge($preferred, $excluded) ?: [0])->column('ID');
        $patient->PreferredCaregivers()->setByIDList(array_values(array_intersect($preferred, $valid)));
        $patient->ExcludedCaregivers()->setByIDList(array_values(array_intersect($excluded, $valid)));
        $this->audit->event($patient, 'patient_wishes_changed', [
            'preferredCaregiverIds' => $preferred,
            'excludedCaregiverIds' => $excluded,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function applyCarePlan(CarePlanItem $item, array $body): void
    {
        if ($this->has($body, 'taskId')) {
            $taskID = (int) $this->intParam($body, 'taskId');
            $task = CareTask::get()->byID($taskID) ?? throw NotFoundException::for('Leistung', $taskID);
            $item->CareTaskID = (int) $task->ID;
        }
        if ($this->has($body, 'slot')) {
            $slot = $this->stringParam($body, 'slot', 10);
            if (!array_key_exists($slot, CarePlanItem::SLOT_LABELS)) {
                throw BadRequestException::because('Unbekannter Einsatz-Slot.');
            }
            $item->Slot = $slot;
        }
        if ($this->has($body, 'weekdays')) {
            $item->Weekdays = $body['weekdays'];
        }
        if ($this->has($body, 'windowStart')) {
            $item->WindowStart = $this->timeParam($body, 'windowStart');
        }
        if ($this->has($body, 'windowEnd')) {
            $item->WindowEnd = $this->timeParam($body, 'windowEnd');
        }
        if ($this->has($body, 'validFrom')) {
            $item->ValidFrom = $this->dateParam($body, 'validFrom', false);
        }
        if ($this->has($body, 'validUntil')) {
            $item->ValidUntil = $this->dateParam($body, 'validUntil', false);
        }
        if ($this->has($body, 'requiredStaff')) {
            $item->RequiredStaff = (int) $body['requiredStaff'];
        }
        if ($this->has($body, 'instruction')) {
            $item->Instruction = $this->stringParam($body, 'instruction', 2000);
        }
    }

    /** @return array<string, mixed> */
    private function summary(Patient $patient, int $planCount): array
    {
        return [
            'id' => (int) $patient->ID,
            'name' => $patient->getTitle(),
            'address' => $patient->getAddressLine(),
            'phone' => $patient->Phone ?: null,
            'isActive' => (bool) $patient->IsActive,
            'isGeocoded' => $patient->getIsGeocoded(),
            'carePlanCount' => $planCount,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Patient $patient): array
    {
        $point = $patient->getGeoPoint();
        $items = $patient->CarePlanItems()->sort(['Slot' => 'ASC', 'WindowStart' => 'ASC', 'ID' => 'ASC'])->toArray();
        $required = [];
        foreach ($items as $item) {
            $level = $item->CareTask()->getMinQualificationLevel();
            $required[$item->Slot] = isset($required[$item->Slot]) ? QualificationLevel::max($required[$item->Slot], $level) : $level;
        }

        return [
            'id' => (int) $patient->ID,
            'firstName' => (string) $patient->FirstName,
            'lastName' => (string) $patient->Surname,
            'name' => $patient->getTitle(),
            'street' => (string) $patient->Street,
            'postalCode' => (string) $patient->PostalCode,
            'city' => (string) $patient->City,
            'country' => (string) $patient->Country,
            'phone' => (string) $patient->Phone,
            'accessNotes' => (string) $patient->AccessNotes,
            'careNotes' => (string) $patient->CareNotes,
            'isActive' => (bool) $patient->IsActive,
            'lat' => $point?->lat,
            'lng' => $point?->lng,
            'geocodedAt' => Clock::iso($patient->GeocodedAt),
            'preferredCaregiverIds' => array_map('intval', $patient->PreferredCaregivers()->column('ID')),
            'excludedCaregiverIds' => array_map('intval', $patient->ExcludedCaregivers()->column('ID')),
            'hasVisits' => PatientVisit::get()->filter('PatientID', $patient->ID)->exists(),
            'slotRequirements' => (object) array_map(static fn (QualificationLevel $l): array => ['value' => $l->value, 'label' => $l->label()], $required),
            'carePlan' => array_map(static fn (CarePlanItem $i): array => [
                'id' => (int) $i->ID,
                'taskId' => (int) $i->CareTaskID,
                'taskCode' => $i->CareTask()->Code,
                'taskTitle' => $i->CareTask()->Title,
                'minQualification' => $i->CareTask()->MinQualification,
                'durationMinutes' => (int) $i->CareTask()->DurationMinutes,
                'slot' => (string) $i->Slot,
                'weekdays' => Weekdays::parse($i->Weekdays),
                'weekdaysLabel' => $i->getWeekdaysLabel(),
                'windowStart' => Clock::short($i->WindowStart),
                'windowEnd' => Clock::short($i->WindowEnd),
                'validFrom' => $i->ValidFrom ?: null,
                'validUntil' => $i->ValidUntil ?: null,
                'requiredStaff' => (int) $i->RequiredStaff,
                'instruction' => (string) $i->Instruction,
                'lastEdited' => Clock::iso($i->LastEdited),
            ], $items),
        ];
    }
}
