<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use JsonSerializable;

/**
 * Result of an optimisation run, shown on the board as a ghost plan. Nothing is persisted
 * until the PDL applies it (RouteProposalApplier).
 */
final class RouteProposal implements JsonSerializable
{
    public const REASON_LABELS = [
        'NO_GEO' => 'Adresse nicht geokodiert',
        'NO_QUALIFIED_CAREGIVER' => 'Keine ausreichend qualifizierte Pflegekraft im Dienst',
        'NO_FEASIBLE_SLOT' => 'Kein passendes Zeitfenster frei',
        'NO_VEHICLES' => 'Keine Pflegekraft im Dienst',
    ];

    /**
     * @param array<int, array<string, mixed>> $routes
     * @param array<int, array<string, mixed>> $unassigned
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $date,
        public readonly string $solver,
        public readonly string $matrixSource,
        public readonly array $routes,
        public readonly array $unassigned,
        public readonly int $totalTravelSeconds,
        public readonly string $generatedAt,
        public readonly array $notes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode,
            'date' => $this->date,
            'solver' => $this->solver,
            'matrixSource' => $this->matrixSource,
            'totalTravelMinutes' => (int) round($this->totalTravelSeconds / 60),
            'generatedAt' => $this->generatedAt,
            'routes' => $this->routes,
            'unassigned' => $this->unassigned,
            'notes' => $this->notes,
        ];
    }
}
