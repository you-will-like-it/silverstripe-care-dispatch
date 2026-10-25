<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\AccessDeniedException;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Model\VisitTask;

/**
 * Field staff actions on their own visits (mobile view).
 */
class VisitExecutionService
{
    use Injectable;

    private static array $dependencies = [
        'attendance' => '%$' . AttendanceService::class,
        'board' => '%$' . BoardDataService::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public AttendanceService $attendance;

    public BoardDataService $board;

    public AuditLogger $audit;

    public function start(Caregiver $caregiver, int $visitID): PatientVisit
    {
        return Transaction::run(function () use ($caregiver, $visitID): PatientVisit {
            Transaction::lock(PatientVisit::class, [$visitID]);
            $visit = $this->ownVisit($caregiver, $visitID);
            $entry = $this->attendance->requireOpen($caregiver);
            if ($entry->Status === TimeEntry::STATUS_BREAK) {
                throw new DomainRuleException('ON_BREAK', 'Bitte zuerst die Pause beenden.');
            }
            if ($visit->Status !== PatientVisit::STATUS_PENDING) {
                throw new DomainRuleException('INVALID_STATUS', sprintf('Einsatz kann im Status "%s" nicht gestartet werden.', $visit->Status));
            }
            if ((string) $visit->VisitDate !== Clock::today()) {
                throw new DomainRuleException('NOT_TODAY', 'Nur Einsätze von heute können gestartet werden.');
            }
            $running = $this->attendance->runningVisit($caregiver);
            if ($running !== null && (int) $running->ID !== (int) $visit->ID) {
                throw new DomainRuleException(
                    'VISIT_RUNNING',
                    sprintf('Einsatz bei %s läuft noch.', $running->Patient()->getTitle())
                );
            }
            $visit->Status = PatientVisit::STATUS_IN_PROGRESS;
            $visit->ActualStart = Clock::nowDb();
            $visit->write();
            $this->refreshTour($visit);
            $this->audit->event($visit, 'visit_started', ['actualStart' => $visit->ActualStart]);

            return $visit;
        });
    }

    /**
     * @param array<int, array{id:int|string, status:string, note?:string|null}> $taskUpdates
     */
    public function complete(Caregiver $caregiver, int $visitID, array $taskUpdates, string $notes = ''): PatientVisit
    {
        return Transaction::run(function () use ($caregiver, $visitID, $taskUpdates, $notes): PatientVisit {
            Transaction::lock(PatientVisit::class, [$visitID]);
            $visit = $this->ownVisit($caregiver, $visitID);
            if ($visit->Status !== PatientVisit::STATUS_IN_PROGRESS) {
                throw new DomainRuleException('INVALID_STATUS', 'Nur laufende Einsätze können abgeschlossen werden.');
            }
            foreach ($taskUpdates as $update) {
                $this->applyTaskUpdate($visit, (int) ($update['id'] ?? 0), (string) ($update['status'] ?? ''), (string) ($update['note'] ?? ''));
            }
            $open = $visit->Tasks()->filter('Status', VisitTask::STATUS_OPEN);
            if ($open->exists()) {
                throw new DomainRuleException(
                    'TASKS_OPEN',
                    'Bitte alle Leistungen als erledigt oder nicht erbracht (mit Begründung) dokumentieren: '
                    . implode(', ', array_map(static fn (VisitTask $t): string => (string) $t->CareTask()->Title, $open->toArray()))
                );
            }
            if (trim($notes) !== '') {
                $visit->Notes = trim(trim((string) $visit->Notes) . "\n" . trim($notes));
            }
            $visit->Status = PatientVisit::STATUS_COMPLETED;
            $visit->ActualEnd = Clock::nowDb();
            $visit->write();
            $this->refreshTour($visit);
            $this->audit->event($visit, 'visit_completed', [
                'actualStart' => $visit->ActualStart,
                'actualEnd' => $visit->ActualEnd,
            ]);

            return $visit;
        });
    }

    public function updateTask(Caregiver $caregiver, int $taskID, string $status, string $note): VisitTask
    {
        return Transaction::run(function () use ($caregiver, $taskID, $status, $note): VisitTask {
            $task = VisitTask::get()->byID($taskID) ?? throw NotFoundException::for('Leistung', $taskID);
            $visit = $this->ownVisit($caregiver, (int) $task->VisitID);
            if ($visit->Status !== PatientVisit::STATUS_IN_PROGRESS) {
                throw new DomainRuleException('INVALID_STATUS', 'Leistungen können nur während eines laufenden Einsatzes dokumentiert werden.');
            }

            return $this->applyTaskUpdate($visit, $taskID, $status, $note);
        });
    }

    /**
     * Mobile payload: the caregiver's tours for $date incl. patient care details.
     *
     * @return array<string, mixed>
     */
    public function myTour(Caregiver $caregiver, string $date): array
    {
        $date = Clock::parseDate($date);
        $tours = DailyTour::get()->filter(['CaregiverID' => $caregiver->ID, 'TourDate' => $date])->sort(['IsAdHoc' => 'ASC', 'ID' => 'ASC']);
        $tourDtos = [];
        foreach ($tours as $tour) {
            $visits = $tour->Visits()->exclude('Status', PatientVisit::STATUS_CANCELLED)->sort(['SortOrder' => 'ASC', 'ScheduledStart' => 'ASC'])->toArray();
            $tasks = $this->board->tasksByVisit(array_map(static fn (PatientVisit $v): int => (int) $v->ID, $visits));
            $visitDtos = [];
            foreach ($visits as $visit) {
                $patient = Patient::get()->byID((int) $visit->PatientID);
                $this->audit->read((int) $visit->PatientID, 'mobile_tour');
                $visitDtos[] = VisitPresenter::visit($visit, $patient, $tasks[(int) $visit->ID] ?? [], [], [], true);
            }
            $tourDtos[] = [
                'id' => (int) $tour->ID,
                'title' => (string) $tour->Title,
                'status' => (string) $tour->Status,
                'isAdHoc' => (bool) $tour->IsAdHoc,
                'visits' => $visitDtos,
            ];
        }
        $entry = TimeEntry::openFor((int) $caregiver->ID);

        return [
            'date' => $date,
            'now' => Clock::now()->format(DATE_ATOM),
            'caregiver' => [
                'id' => (int) $caregiver->ID,
                'name' => $caregiver->getTitle(),
                'qualification' => $caregiver->getQualification()->label(),
            ],
            'attendance' => $entry === null ? ['status' => 'Absent'] : [
                'status' => (string) $entry->Status,
                'checkIn' => Clock::iso($entry->CheckIn),
                'breakMinutes' => $entry->getBreakMinutes(),
            ],
            'tours' => $tourDtos,
        ];
    }

    /** Returns the caregiver's open tour for SOS from the field. */
    public function ownOpenTour(Caregiver $caregiver, ?int $tourID): DailyTour
    {
        $list = DailyTour::get()->filter([
            'CaregiverID' => $caregiver->ID,
            'TourDate' => Clock::today(),
            'Status' => [DailyTour::STATUS_ASSIGNED, DailyTour::STATUS_IN_PROGRESS],
        ]);
        if ($tourID) {
            $list = $list->filter('ID', $tourID);
        }
        $tour = $list->sort(['Status' => 'DESC', 'ID' => 'ASC'])->first();
        if ($tour === null) {
            throw new DomainRuleException('NO_OPEN_TOUR', 'Du hast heute keine laufende Tour.');
        }

        return $tour;
    }

    private function applyTaskUpdate(PatientVisit $visit, int $taskID, string $status, string $note): VisitTask
    {
        if (!in_array($status, [VisitTask::STATUS_OPEN, VisitTask::STATUS_DONE, VisitTask::STATUS_NOT_DONE], true)) {
            throw BadRequestException::because(sprintf('Ungültiger Leistungsstatus "%s".', $status));
        }
        $task = $visit->Tasks()->byID($taskID) ?? throw NotFoundException::for('Leistung', $taskID);
        if ($status === VisitTask::STATUS_NOT_DONE && trim($note) === '') {
            throw new DomainRuleException('NOTE_REQUIRED', sprintf('Bitte begründen, warum "%s" nicht erbracht wurde.', $task->CareTask()->Title));
        }
        if ($task->Status === $status && trim((string) $task->Note) === trim($note)) {
            return $task;
        }
        $task->Status = $status;
        $task->Note = trim($note);
        if ($status === VisitTask::STATUS_OPEN) {
            $task->DoneAt = null;
            $task->DoneByID = 0;
        } else {
            $task->DoneAt = Clock::nowDb();
            $task->DoneByID = (int) (Security::getCurrentUser()?->ID ?? 0);
        }
        $task->write();

        return $task;
    }

    private function ownVisit(Caregiver $caregiver, int $visitID): PatientVisit
    {
        $visit = PatientVisit::get()->byID($visitID) ?? throw NotFoundException::for('Einsatz', $visitID);
        if (!$visit->DailyTourID || (int) $visit->DailyTour()->CaregiverID !== (int) $caregiver->ID) {
            throw AccessDeniedException::because('Dieser Einsatz gehört nicht zu deiner Tour.');
        }

        return $visit;
    }

    private function refreshTour(PatientVisit $visit): void
    {
        $tour = $visit->DailyTour();
        if ($tour->exists()) {
            $tour->refreshStatus();
            $tour->write();
        }
    }
}
