<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Routing;

use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingProblem;
use YouWillLikeIT\CareDispatch\Routing\Problem\RoutingSolution;

interface RouteSolverInterface
{
    public function solve(RoutingProblem $problem): RoutingSolution;

    public function name(): string;
}
