<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\CareDispatchException;
use YouWillLikeIT\CareDispatch\Model\PatientVisit;
use YouWillLikeIT\CareDispatch\Service\AuditLogger;
use YouWillLikeIT\CareDispatch\Service\VisitAssignmentService;

/**
 * Applies an accepted RouteProposal stop by stop through the regular assignment service,
 * so every hard rule is re-checked. Soft warnings count as confirmed by the PDL (reason is
 * stored). Stops whose visit changed since the proposal was computed are skipped as STALE.
 */
class RouteProposalApplier
{
    use Injectable;

    private static array $dependencies = [
        'assignment' => '%$' . VisitAssignmentService::class,
        'audit' => '%$' . AuditLogger::class,
    ];

    public VisitAssignmentService $assignment;

    public AuditLogger $audit;

    /**
     * @param array<string, mixed> $proposal as produced by RouteProposal::jsonSerialize()
     * @return array{applied:int, skipped:int, failures: array<int, array{visitId:int, code:string, message:string}>}
     */
    public function apply(string $date, array $proposal, string $reason): array
    {
        if (($proposal['date'] ?? null) !== $date || !isset($proposal['routes']) || !is_array($proposal['routes'])) {
            throw BadRequestException::because('Vorschlag passt nicht zum gewählten Tag oder ist unvollständig.');
        }
        $applied = 0;
        $skipped = 0;
        $failures = [];

        foreach ($proposal['routes'] as $route) {
            $caregiverID = (int) ($route['caregiverId'] ?? 0);
            $tourID = isset($route['tourId']) ? (int) $route['tourId'] : 0;
            if ($caregiverID <= 0 || !is_array($route['stops'] ?? null)) {
                continue;
            }
            foreach ($route['stops'] as $stop) {
                if (!is_array($stop) || !empty($stop['pinned']) || empty($stop['changed'])) {
                    $skipped++;
                    continue;
                }
                $visitID = (int) ($stop['visitId'] ?? 0);
                $start = (string) ($stop['start'] ?? '');
                $stale = $this->staleReason($visitID, (array) ($stop['basis'] ?? []));
                if ($stale !== null) {
                    $failures[] = ['visitId' => $visitID, 'code' => 'STALE', 'message' => $stale];
                    continue;
                }
                try {
                    Clock::parseTime($start);
                    $this->assignment->assignVisit(
                        $visitID,
                        $tourID > 0 ? $tourID : null,
                        $tourID > 0 ? null : $caregiverID,
                        $start,
                        true,
                        $reason
                    );
                    $applied++;
                } catch (CareDispatchException $e) {
                    $failures[] = ['visitId' => $visitID, 'code' => $e->errorCode(), 'message' => $e->getMessage()];
                } catch (InvalidArgumentException $e) {
                    $failures[] = ['visitId' => $visitID, 'code' => 'BAD_REQUEST', 'message' => $e->getMessage()];
                }
            }
        }

        $this->audit->systemEvent(self::class, 'route_proposal_applied', [
            'date' => $date,
            'mode' => $proposal['mode'] ?? null,
            'solver' => $proposal['solver'] ?? null,
            'applied' => $applied,
            'skipped' => $skipped,
            'failures' => $failures,
        ], $reason);

        return ['applied' => $applied, 'skipped' => $skipped, 'failures' => $failures];
    }

    /** @param array<string, mixed> $basis */
    private function staleReason(int $visitID, array $basis): ?string
    {
        $visit = PatientVisit::get()->byID($visitID);
        if ($visit === null) {
            return 'Einsatz existiert nicht mehr.';
        }
        if (($basis['status'] ?? null) !== $visit->Status
            || (int) ($basis['tourId'] ?? 0) !== (int) $visit->DailyTourID
            || ($basis['start'] ?? null) !== Clock::short($visit->ScheduledStart)) {
            return 'Einsatz wurde seit der Berechnung geändert – bitte neu optimieren.';
        }

        return null;
    }
}
