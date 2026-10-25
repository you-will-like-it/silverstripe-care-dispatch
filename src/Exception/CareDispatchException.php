<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

use RuntimeException;
use YouWillLikeIT\CareDispatch\Domain\Violation;

/**
 * Base class for expected, client-facing errors. Rendered as JSON by the API controllers.
 */
abstract class CareDispatchException extends RuntimeException
{
    /**
     * @param Violation[] $violations
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return Violation[] */
    public function violations(): array
    {
        return $this->violations;
    }
}
