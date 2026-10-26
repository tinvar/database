<?php

declare(strict_types=1);

use Tinvar\Orm\Attributes\HasMany;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Collection;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;

#[Table('misrelated')]
final class MisrelatedModel extends Model
{
    #[Id] public private(set) string $id;

    /** @var Collection<stdClass> */
    #[HasMany(stdClass::class)]
    public Collection $things;
}

// The attribute takes any class name; a relation to something that is not
// a model failed only when first read. It is refused when the model is
// compiled.
it('refuses a relation whose target is not a model', function () {
    expect(fn () => Metadata::for(MisrelatedModel::class))
        ->toThrow(MappingException::class, 'MisrelatedModel::$things relates to stdClass, which is not a Model.');
});
