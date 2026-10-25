<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

final class NotFoundException extends CareDispatchException
{
    public static function for(string $what, int $id): self
    {
        return new self('NOT_FOUND', sprintf('%s #%d wurde nicht gefunden.', $what, $id));
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
