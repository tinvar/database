<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * One change to an #[Audited] model's row, as the ORM hands it to what
 * records changes (ModelChanges, ADR 0156): which row, what happened to it,
 * and each field it changed from what to what.
 *
 * The fields are already what may be recorded: a field the model names in
 * `except:` is not here, and a secret's value is `[redacted]` on both sides
 * (AuditedColumns). Each value is as the database stores it -- a string, a
 * number, a bool or null -- so it can be written as JSON and read back the
 * same.
 */
final readonly class ModelChange
{
    /** What a secret's value is recorded as. */
    public const string REDACTED = '[redacted]';

    /**
     * @param class-string<Model> $model
     * @param string|null $tenant the tenant the row belongs to, read off it, for a
     *        #[TenantScoped] model; null for any other, whose change is the tenant in force's
     * @param array<string, array{0: scalar|null, 1: scalar|null}> $changes field => [before, after]:
     *        before null for a row created, after null for one deleted
     */
    public function __construct(
        public ModelChangeKind $kind,
        public string $model,
        public string $id,
        public ?string $tenant,
        public array $changes,
    ) {}
}
