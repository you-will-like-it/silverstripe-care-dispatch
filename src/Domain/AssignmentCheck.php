<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Domain;

use YouWillLikeIT\CareDispatch\Exception\AssignmentConflictException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;

/**
 * Collected result of validating one or more assignments.
 */
final class AssignmentCheck
{
    /** @var Violation[] */
    private array $violations = [];

    public function add(Violation $violation): void
    {
        $this->violations[] = $violation;
    }

    public function merge(self $other): void
    {
        foreach ($other->all() as $violation) {
            $this->add($violation);
        }
    }

    /** @return Violation[] */
    public function all(): array
    {
        return $this->violations;
    }

    /** @return Violation[] */
    public function hard(): array
    {
        return array_values(array_filter($this->violations, static fn (Violation $v): bool => $v->isHard()));
    }

    /** @return Violation[] */
    public function soft(): array
    {
        return array_values(array_filter($this->violations, static fn (Violation $v): bool => !$v->isHard()));
    }

    public function hasHard(): bool
    {
        return $this->hard() !== [];
    }

    public function hasSoft(): bool
    {
        return $this->soft() !== [];
    }

    /**
     * Throws when the assignment must not proceed:
     * - any hard violation -> DomainRuleException (422)
     * - soft violations without force -> AssignmentConflictException (409, client asks for confirmation)
     * - soft violations with force but without reason -> DomainRuleException (422)
     */
    public function assertAllowed(bool $force, string $reason): void
    {
        if ($this->hasHard()) {
            throw new DomainRuleException('ASSIGNMENT_REJECTED', 'Zuweisung nicht zulässig.', $this->hard());
        }
        if ($this->hasSoft() && !$force) {
            throw new AssignmentConflictException($this->soft());
        }
        if ($this->hasSoft() && trim($reason) === '') {
            throw new DomainRuleException(
                'OVERRIDE_REASON_REQUIRED',
                'Für das Übersteuern von Warnungen ist eine Begründung erforderlich.',
                $this->soft()
            );
        }
    }
}
