<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\AccessDeniedException;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Routing\RoutePlanner;
use YouWillLikeIT\CareDispatch\Routing\RouteProposalApplier;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;
use YouWillLikeIT\CareDispatch\Service\BoardDataService;
use YouWillLikeIT\CareDispatch\Service\TourGenerationService;
use YouWillLikeIT\CareDispatch\Service\TourInterruptService;
use YouWillLikeIT\CareDispatch\Service\VisitAssignmentService;

/**
 * PDL board API. Routed at /api/dispatch (see _config/routes.yml).
 *
 * All mutating endpoints return {"result": ..., "board": <fresh board payload>} so the client
 * always renders server truth after a change.
 */
class DispatchApiController extends JsonApiController
{
    private static string $required_permission = CareDispatchPermissions::DISPATCH;

    private static array $url_handlers = [
        'GET board-data' => 'boardData',
        'POST tour/$ID!/interrupt' => 'interruptTour',
        'POST tour/$ID!/assign-caregiver' => 'assignTourCaregiver',
        'POST visit/$ID!/companion' => 'addCompanion',
        'POST assign-visit' => 'assignVisit',
        'POST unassign-visit' => 'unassignVisit',
        'POST generate-day' => 'generateDay',
        'POST optimize' => 'optimize',
        'POST apply-proposal' => 'applyProposal',
        'GET patient/$ID!/history' => 'patientHistory',
        'GET patient/$ID!' => 'patient',
    ];

    private static array $allowed_actions = [
        'boardData',
        'interruptTour',
        'assignTourCaregiver',
        'addCompanion',
        'assignVisit',
        'unassignVisit',
        'generateDay',
        'optimize',
        'applyProposal',
        'patientHistory',
        'patient',
    ];

    private static array $dependencies = [
        'board' => '%$' . BoardDataService::class,
        'assignment' => '%$' . VisitAssignmentService::class,
        'interrupt' => '%$' . TourInterruptService::class,
        'generator' => '%$' . TourGenerationService::class,
        'planner' => '%$' . RoutePlanner::class,
        'applier' => '%$' . RouteProposalApplier::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public BoardDataService $board;

    public VisitAssignmentService $assignment;

    public TourInterruptService $interrupt;

    public TourGenerationService $generator;

    public RoutePlanner $planner;

    public RouteProposalApplier $applier;

    public AuditLogger $audit;

    /** GET /api/dispatch/board-data?date=YYYY-MM-DD */
    public function boardData(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(fn (): array => $this->board->build(Clock::parseDate($request->getVar('date'))));
    }

    /** POST /api/dispatch/tour/{id}/interrupt  {reason, current_visit_outcome?: "completed"|"not_completed"} */
    public function interruptTour(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body();
            $outcome = $this->stringParam($body, 'current_visit_outcome', 20) ?: null;
            $result = $this->interrupt->interrupt(
                $this->routeID($request),
                $this->stringParam($body, 'reason'),
                $outcome,
                'pdl'
            );

            return $this->withBoard((string) $result['tour']->TourDate, [
                'tourId' => (int) $result['tour']->ID,
                'pooledVisitIds' => $result['pooledVisitIds'],
                'keptVisitIds' => $result['keptVisitIds'],
                'affectedTourIds' => $result['affectedTourIds'],
            ]);
        });
    }

    /** POST /api/dispatch/tour/{id}/assign-caregiver  {caregiver_id|null, force?, reason?} */
    public function assignTourCaregiver(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body();
            $tour = $this->assignment->assignTourCaregiver(
                $this->routeID($request),
                $this->intParam($body, 'caregiver_id', false),
                $this->boolParam($body, 'force'),
                $this->stringParam($body, 'reason')
            );

            return $this->withBoard((string) $tour->TourDate, ['tourId' => (int) $tour->ID, 'status' => $tour->Status]);
        });
    }

    /**
     * POST /api/dispatch/assign-visit
     * {visit_id, target_tour_id? | target_caregiver_id?, target_time?: "HH:MM", force?: bool, reason?: string}
     *
     * 409 = soft warnings, resend with force=true and reason. 422 = hard rule violated.
     */
    public function assignVisit(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $body = $this->body();
            $tourID = $this->intParam($body, 'target_tour_id', false);
            $caregiverID = $this->intParam($body, 'target_caregiver_id', false);
            if ($tourID && $caregiverID) {
                throw BadRequestException::because('Entweder target_tour_id oder target_caregiver_id angeben, nicht beides.');
            }
            $time = $this->stringParam($body, 'target_time', 8) ?: null;
            $result = $this->assignment->assignVisit(
                (int) $this->intParam($body, 'visit_id'),
                $tourID,
                $caregiverID,
                $time,
                $this->boolParam($body, 'force'),
                $this->stringParam($body, 'reason')
            );

            return $this->withBoard((string) $result['visit']->VisitDate, [
                'visitId' => (int) $result['visit']->ID,
                'tourId' => (int) $result['tour']->ID,
                'createdTour' => $result['createdTour'],
                'overriddenWarnings' => $result['overridden'],
            ]);
        });
    }

    /** POST /api/dispatch/unassign-visit  {visit_id, reason?} */
    public function unassignVisit(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $body = $this->body();
            $visit = $this->assignment->unassignVisit((int) $this->intParam($body, 'visit_id'), $this->stringParam($body, 'reason'));

            return $this->withBoard((string) $visit->VisitDate, ['visitId' => (int) $visit->ID]);
        });
    }

    /** POST /api/dispatch/visit/{id}/companion  {caregiver_id, force?, reason?} */
    public function addCompanion(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body();
            $result = $this->assignment->addCompanion(
                $this->routeID($request),
                (int) $this->intParam($body, 'caregiver_id'),
                $this->boolParam($body, 'force'),
                $this->stringParam($body, 'reason')
            );

            return $this->withBoard((string) $result['visit']->VisitDate, [
                'companionVisitId' => (int) $result['visit']->ID,
                'tourId' => (int) $result['tour']->ID,
                'overriddenWarnings' => $result['overridden'],
            ]);
        });
    }

    /** POST /api/dispatch/generate-day  {date} */
    public function generateDay(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $body = $this->body();
            $date = Clock::parseDate($this->stringParam($body, 'date', 10) ?: null);
            if ($date < Clock::today()) {
                throw BadRequestException::because('Vergangene Tage können nicht generiert werden.');
            }
            $summary = $this->generator->generate($date);

            return $this->withBoard($date, $summary);
        });
    }

    /**
     * POST /api/dispatch/optimize
     * {date, mode: "tour", tour_id} – reorder one tour
     * {date, mode: "pool"}          – distribute the unassigned pool onto caregivers on duty
     *
     * Returns a proposal only; nothing is changed until apply-proposal.
     */
    public function optimize(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $body = $this->body();
            $date = Clock::parseDate($this->stringParam($body, 'date', 10) ?: null);
            $mode = $this->stringParam($body, 'mode', 10);
            $proposal = match ($mode) {
                'tour' => $this->planner->optimizeTour((int) $this->intParam($body, 'tour_id'), $date),
                'pool' => $this->planner->distributePool($date),
                default => throw BadRequestException::because('mode muss "tour" oder "pool" sein.'),
            };

            return ['proposal' => $proposal->jsonSerialize()];
        });
    }

    /** POST /api/dispatch/apply-proposal  {date, proposal: {...}, reason?} */
    public function applyProposal(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $body = $this->body();
            $date = Clock::parseDate($this->stringParam($body, 'date', 10) ?: null);
            $proposal = $body['proposal'] ?? null;
            if (!is_array($proposal)) {
                throw BadRequestException::because('proposal fehlt.');
            }
            $result = $this->applier->apply($date, $proposal, $this->stringParam($body, 'reason') ?: 'Routenvorschlag übernommen');

            return $this->withBoard($date, $result);
        });
    }

    /** GET /api/dispatch/patient/{id} – detail incl. care plan (Zugriffsprotokoll if enabled). */
    public function patient(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $id = $this->routeID($request);
            $patient = Patient::get()->byID($id) ?? throw NotFoundException::for('Patient', $id);
            $this->audit->read($id, 'board_patient_detail');
            $point = $patient->getGeoPoint();

            return [
                'id' => (int) $patient->ID,
                'name' => $patient->getTitle(),
                'address' => $patient->getAddressLine(),
                'phone' => $patient->Phone ?: null,
                'accessNotes' => $patient->AccessNotes ?: null,
                'careNotes' => $patient->CareNotes ?: null,
                'lat' => $point?->lat,
                'lng' => $point?->lng,
                'preferredCaregiverIds' => array_map('intval', $patient->PreferredCaregivers()->column('ID')),
                'excludedCaregiverIds' => array_map('intval', $patient->ExcludedCaregivers()->column('ID')),
                'carePlan' => array_map(static fn ($item): array => [
                    'id' => (int) $item->ID,
                    'task' => $item->CareTask()->Title,
                    'code' => $item->CareTask()->Code,
                    'minQualification' => $item->CareTask()->MinQualification,
                    'slot' => $item->Slot,
                    'window' => $item->getWindowLabel(),
                    'weekdays' => $item->getWeekdaysLabel(),
                    'instruction' => $item->Instruction ?: null,
                    'requiredStaff' => (int) $item->RequiredStaff,
                    'validFrom' => $item->ValidFrom,
                    'validUntil' => $item->ValidUntil,
                ], $patient->CarePlanItems()->sort('Slot')->toArray()),
            ];
        });
    }

    /** GET /api/dispatch/patient/{id}/history?limit=100 – audit trail of a patient. */
    public function patientHistory(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            if (!CareDispatchPermissions::check(CareDispatchPermissions::AUDIT, $this->currentMember())) {
                throw AccessDeniedException::because('Für das Änderungsprotokoll fehlt die Berechtigung.');
            }
            $id = $this->routeID($request);
            $limit = max(1, min(500, (int) ($request->getVar('limit') ?: 100)));
            $entries = AuditEntry::get()->filter('PatientID', $id)->sort(['Created' => 'DESC', 'ID' => 'DESC'])->limit($limit);

            return [
                'patientId' => $id,
                'entries' => array_map(static fn (AuditEntry $e): array => [
                    'id' => (int) $e->ID,
                    'at' => Clock::iso($e->Created),
                    'member' => $e->MemberName,
                    'action' => $e->Action,
                    'subject' => $e->getSubjectLabel(),
                    'changes' => $e->getChangesArray(),
                    'reason' => $e->Reason ?: null,
                ], $entries->toArray()),
            ];
        });
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withBoard(string $date, array $result): array
    {
        return ['result' => $result, 'board' => $this->board->build($date)];
    }
}
