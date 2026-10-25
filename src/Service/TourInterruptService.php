<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Service;

use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Model\VisitTask;

/**
 * Incident mode (SOS / Tour-Split).
 *
 * - Completed visits stay with the caregiver (documentation, billing).
 * - A running visit is resolved by the reporter: "completed" closes it now,
 *   "not_completed" sends it back to the pool; its ActualStart is preserved in the audit trail.
 * - All pending visits leave the tour and go to the unassigned pool.
 * - Companion visits of every primary that went to the pool follow it into the pool.
 * - The tour becomes Interrupted (terminal).
 */
class TourInterruptService
{
    use Injectable;

    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_NOT_COMPLETED = 'not_completed';

    private static array $dependencies = [
        'assignment' => '%$' . VisitAssignmentService::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public VisitAssignmentService $assignment;

    public AuditLogger $audit;

    /**
     * @return array{tour: DailyTour, pooledVisitIds: int[], keptVisitIds: int[], affectedTourIds: int[]}
     */
    public function interrupt(int $tourID, string $reason, ?string $currentVisitOutcome, string $source): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw BadRequestException::because('Bitte einen Grund für die Unterbrechung angeben.');
        }
        if ($currentVisitOutcome !== null
            && !in_array($currentVisitOutcome, [self::OUTCOME_COMPLETED, self::OUTCOME_NOT_COMPLETED], true)) {
            throw BadRequestException::because('current_visit_outcome muss "completed" oder "not_completed" sein.');
        }

        return Transaction::run(function () use ($tourID, $reason, $currentVisitOutcome, $source): array {
            Transaction::lock(DailyTour::class, [$tourID]);
            $tour = DailyTour::get()->byID($tourID) ?? throw NotFoundException::for('Tour', $tourID);
            if (!in_array($tour->Status, [DailyTour::STATUS_ASSIGNED, DailyTour::STATUS_IN_PROGRESS], true)) {
                throw new DomainRuleException(
                    'TOUR_NOT_INTERRUPTIBLE',
                    sprintf('Nur zugewiesene oder laufende Touren können unterbrochen werden (Status: %s).', $tour->Status)
                );
            }

            $visits = $tour->Visits()->sort('SortOrder');
            Transaction::lock(PatientVisit::class, $visits->column('ID'));

            $running = $visits->filter('Status', PatientVisit::STATUS_IN_PROGRESS)->first();
            if ($running !== null && $currentVisitOutcome === null) {
                throw new DomainRuleException(
                    'OUTCOME_REQUIRED',
                    sprintf(
                        'Einsatz bei %s läuft gerade. Bitte angeben, ob er abgeschlossen wurde.',
                        $running->Patient()->getTitle()
                    )
                );
            }

            $now = Clock::nowDb();
            $pooled = [];
            $kept = [];
            $affectedTours = [];

            if ($running !== null) {
                if ($currentVisitOutcome === self::OUTCOME_COMPLETED) {
                    $running->Status = PatientVisit::STATUS_COMPLETED;
                    $running->ActualEnd = $now;
                    $running->write();
                    $this->closeOpenTasks($running, 'Tour unterbrochen: ' . $reason);
                    $this->audit->event($running, 'visit_completed_on_sos', ['actualEnd' => $now], $reason);
                    $kept[] = (int) $running->ID;
                } else {
                    $this->audit->event($running, 'visit_aborted_on_sos', [
                        'actualStart' => $running->ActualStart,
                        'abortedAt' => $now,
                        'caregiverId' => (int) $tour->CaregiverID,
                        'doneTaskIds' => $running->Tasks()->filter('Status', VisitTask::STATUS_DONE)->column('ID'),
                    ], $reason);
                    // Tasks already documented as done stay done (e.g. medication given),
                    // so the next caregiver does not repeat them.
                    $running->ActualStart = null;
                    $running->ActualEnd = null;
                    $this->assignment->moveToPool($running);
                    $pooled[] = (int) $running->ID;
                }
            }

            foreach ($visits as $visit) {
                if ($visit->Status === PatientVisit::STATUS_COMPLETED) {
                    if (!in_array((int) $visit->ID, $kept, true)) {
                        $kept[] = (int) $visit->ID;
                    }
                    continue;
                }
                if ($visit->Status !== PatientVisit::STATUS_PENDING) {
                    continue;
                }
                $this->assignment->moveToPool($visit);
                $pooled[] = (int) $visit->ID;
            }

            // Companions on other tours follow their primary into the pool.
            if ($pooled !== []) {
                $companions = PatientVisit::get()->filter([
                    'PrimaryVisitID' => $pooled,
                    'Status' => PatientVisit::STATUS_PENDING,
                ]);
                foreach ($companions as $companion) {
                    $affectedTours[] = (int) $companion->DailyTourID;
                    $this->assignment->moveToPool($companion);
                    $pooled[] = (int) $companion->ID;
                    $this->audit->event($companion, 'companion_released_on_sos', ['primaryVisitId' => (int) $companion->PrimaryVisitID], $reason);
                }
            }

            $tour->Status = DailyTour::STATUS_INTERRUPTED;
            $tour->InterruptedAt = $now;
            $tour->InterruptReason = $reason;
            $tour->write();

            $affectedTours = array_values(array_unique(array_filter($affectedTours)));
            foreach ($affectedTours as $affectedID) {
                $this->assignment->afterTourChange(DailyTour::get()->byID($affectedID));
            }

            $this->audit->event($tour, 'sos_interrupt', [
                'source' => $source,
                'caregiverId' => (int) $tour->CaregiverID,
                'runningVisitId' => $running?->ID,
                'runningVisitOutcome' => $running !== null ? $currentVisitOutcome : null,
                'pooledVisitIds' => $pooled,
                'keptVisitIds' => $kept,
                'affectedTourIds' => $affectedTours,
            ], $reason);

            return [
                'tour' => $tour,
                'pooledVisitIds' => array_values(array_unique($pooled)),
                'keptVisitIds' => $kept,
                'affectedTourIds' => $affectedTours,
            ];
        });
    }

    private function closeOpenTasks(PatientVisit $visit, string $note): void
    {
        foreach ($visit->Tasks()->filter('Status', VisitTask::STATUS_OPEN) as $task) {
            $task->Status = VisitTask::STATUS_NOT_DONE;
            $task->Note = $note;
            $task->write();
        }
    }
}
