<?php

declare(strict_types=1);

namespace Tinvar\Orm\Builder;

use Tinvar\Orm\Change;

/**
 * A Change, checked against the metadata by Fields and resolved: the column
 * a copy reads, as the metadata spells it. What update() writes for it is
 * made here and nowhere else, from the Change's kind, numbers bound and
 * columns quoted (ADR 0073).
 *
 * @internal for the query builder, inside this package
 */
final readonly class Assignment
{
    public function __construct(
        public Change $change,
        public ?string $source = null,
    ) {}

    /**
     * The right-hand side of `"column" = ...`, and its bindings in order.
     *
     * @param string|null $table the table to name each column read by: an upsert's, where the row there
     *        is read beside the new one -- Postgres finds a bare column ambiguous between them (ADR 0123)
     * @return array{string, list<int|float>}
     */
    public function sql(string $column, Compiler $compiler, ?string $table = null): array
    {
        $change = $this->change;
        $of = $table === null ? '' : Identifiers::quote($table) . '.';
        $quoted = $of . Identifiers::quote($column);
        [$sql, $bindings] = match ($change->kind) {
            Change::ADD => ["{$quoted} + " . $compiler->placeholder($change->operand), [$change->operand ?? 0]],
            Change::MULTIPLY => ["{$quoted} * " . $compiler->placeholder($change->operand), [$change->operand ?? 1]],
            Change::COPY => [$of . Identifiers::quote($this->source ?? throw new \LogicException('A copy names the column it reads.')), []],
        };

        // A bound reads what it bounds twice, so binds its values twice: no
        // database lets a statement name one value in two places alike.
        foreach ([[$change->floor, '<'], [$change->ceiling, '>']] as [$bound, $operator]) {
            if ($bound === null) {
                continue;
            }
            $placeholder = $compiler->placeholder($bound);
            $sql = "CASE WHEN ({$sql}) {$operator} {$placeholder} THEN {$placeholder} ELSE ({$sql}) END";
            $bindings = [...$bindings, $bound, $bound, ...$bindings];
        }

        return [$sql, $bindings];
    }
}
