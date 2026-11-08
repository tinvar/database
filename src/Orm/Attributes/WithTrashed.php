<?php

declare(strict_types=1);

namespace Tinvar\Orm\Attributes;

/**
 * Lets a model parameter bind a trashed row (ADR 0191): a route loader's,
 * an endpoint's path model or an action's own model. Without it a trashed
 * row of a #[SoftDeletes] model is a 404, as a row gone is.
 *
 *     #[Action, Authorize('restore', 'order')]
 *     public function restore(#[WithTrashed] Order $order): void { $this->orm->restore($order); }
 *
 * Its tenant still holds it: a trashed row of another tenant's is a 404
 * whatever the parameter says.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class WithTrashed
{
    /** Whether $parameter carries it. */
    public static function on(\ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(self::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
    }
}
