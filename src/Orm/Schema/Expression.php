<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

/**
 * SQL written into a statement as it is: a default the database computes,
 * such as `CURRENT_TIMESTAMP`, where a plain string would be quoted as a
 * literal. The migration author's SQL, never anyone's input.
 */
final readonly class Expression
{
    public function __construct(public string $sql) {}
}
