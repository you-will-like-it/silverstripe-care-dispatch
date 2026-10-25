<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing\Problem;

final readonly class RouteStop
{
    public function __construct(
        public int $jobId,
        public int $arrival,
        public int $start,
        public int $end,
        public int $travelSeconds,
    ) {
    }
}
