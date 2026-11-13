<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Carbon\CarbonImmutable;
use Tinvar\Support\Lazy;
use Tinvar\Support\Uuid;

/**
 * The ORM runtime for one request: a connection, an identity map, a relation-row
 * cache and, during a render, a batch scheduler.
 *
 * Relation loads are cached as raw rows rather than models so every consumer
 * hydrates through the identity map and ends up holding the same instances.
 */
final class Orm
{
    /**
     * The classes whose own statements name the tenants of what they write
     * (noteRowTenants()), and so are not held to guardStatement(): every
     * statement the ORM builds is sent from one of these.
     */
    private const array ORM_WRITERS = [self::class, Query::class];

    /** What a #[BelongsToMany]'s load reads the owner's key as, beside the target's columns (ADR 0192). */
    private const string LINKED_BY = 'tinvar_linked_by';

    /** @var array<string, array<string, mixed>> relation key => group key => rows */
    private array $relationRows = [];

    /**
     * How many writes this Orm has followed, and transactions it has rolled
     * back: what a widened read's rows, kept on the result set they were
     * read for (widenedRows()), are checked against, so a write made since
     * they were read has them read again.
     */
    private int $writeGeneration = 0;

    private ?BatchScheduler $scheduler = null;

    /**
     * Where each lazy relation load is recorded, with whether it ran in a
     * batch (LazyLoad) -- for the testing layer's
     * assertNoLazyLoadsOutsideBatch(). Null unless a test turned it on, so a
     * load in a request pays a null check for it and nothing more.
     */
    private ?LazyLoads $lazyLoads = null;

    /**
     * What each lazy relation load and each acrossTenants() call is told to,
     * with where it was made: the dev toolbar's, under `tinvar serve --dev`
     * (flagLazyLoads(), flagAcrossTenants()). Null otherwise, so a request
     * pays a null check and no backtrace.
     *
     * @var (\Closure(LazyLoad): void)|null
     */
    private ?\Closure $lazyLoadFlag = null;

    /** @var (\Closure(string): void)|null given the call's site, as file:line */
    private ?\Closure $acrossTenantsFlag = null;

    /**
     * What observeWrites() was handed, by its owner's object id: each told
     * of every row written, by class and key, once the write is made, in
     * the order they registered. The owner is held so its id is not reused
     * while it observes. Kept across flush(), like the flags.
     *
     * @var array<int, array{object, \Closure(class-string<Model>, string, bool): void}>
     */
    private array $writeObservers = [];

    /**
     * The open transactions' journals and afterCommit() callbacks: the
     * connection's one transaction's (TransactionFrames), or, on a
     * ConcurrentConnection, each fiber's own.
     */
    private TransactionFrames $mainFrames;

    /** @var \WeakMap<object, TransactionFrames> */
    private \WeakMap $framesByFiber;

    /** What connection() hands out while raw statements are flagged; null while they are not. */
    private ?RawStatements $rawStatements = null;

    /**
     * Where each change to an #[Audited] model is recorded (ADR 0156), or
     * null for nowhere -- and then such a model is not written at all.
     */
    private ?ModelChanges $changes = null;

    /**
     * Depth of acrossTenants() calls outside any fiber.
     *
     * Kept per fiber as well, below: "Context is fiber-local, so a suspended
     * fiber can't leak a switch to another." Inside a render the scheduler
     * interleaves fibers, so a single shared flag would lift scoping for every
     * other query running in the same tick.
     */
    private int $unscopedMain = 0;

    /** @var \WeakMap<object, int> */
    private \WeakMap $unscopedByFiber;

    /**
     * Depth of readOnly() calls, per fiber for the same reason as above: the
     * render scheduler interleaves loaders and query bodies, so a single
     * shared flag would make one fiber's read-only context everyone's.
     */
    private int $readOnlyMain = 0;

    /** @var \WeakMap<object, int> */
    private \WeakMap $readOnlyByFiber;

    /**
     * The subtree withinSubtree() widened reads to, outside any fiber: its
     * root and every tenant in it, root first; null for none.
     *
     * Per fiber as well, and -- as acrossTenants() is -- not inherited by a
     * fiber from the main context: a render's fibers each start at their
     * tenant exactly, and widen only where their own code says so.
     *
     * @var array{string, non-empty-list<string>}|null
     */
    private ?array $subtreeMain = null;

    /** @var \WeakMap<object, array{string, non-empty-list<string>}> */
    private \WeakMap $subtreeByFiber;

    /** @var list<string> the subtree scopeName() last took a digest of */
    private array $scopeDigestOf = [];

    private string $scopeDigest = '';

    /**
     * The tenant a membership granted reads across the subtree of, or null
     * for none: set by the panel stack (RequirePanelMembership) for a panel
     * defined with `subtree: true`, after its check, to the tenant the
     * membership was held in. Inherited by a fiber from the main context,
     * as the tenant is -- the render's fibers run inside the request the
     * grant was made for.
     */
    private ?string $subtreeGrantMain = null;

    /**
     * Per fiber, where a fiber's own code granted or withheld it; '' for
     * withheld, since a fiber with no entry inherits the main context's.
     *
     * @var \WeakMap<object, string>
     */
    private \WeakMap $subtreeGrantByFiber;

    /**
     * The tenants switched into while tenantsEnteredBy() runs: one frame per
     * call open. What an action wrote as another tenant it wrote there, and
     * that tenant's pages are the ones to tell.
     *
     * @var list<non-empty-list<string>>
     */
    private array $entered = [];

    /**
     * Every model a read has filled since recordReads() turned recording
     * on, for the testing layer's model-leak check; null while it is off.
     *
     * @var list<Model>|null
     */
    private ?array $reads = null;

    /**
     * Every string a column read without a model -- pluck(), min(), max()
     * -- returned while reads are recorded, by the model and column it is
     * of: the model-leak check looks for these as it does for a model's
     * columns, or `pluck('passwordHash')` copied into a Data field would
     * pass it. Null while recording is off.
     *
     * @var list<array{model: class-string<Model>, column: string, value: string}>|null
     */
    private ?array $columnReads = null;

    /**
     * Whether tenancy was registered with this Orm: without it, no
     * #[TenantScoped] model is read or written at all.
     */
    private readonly bool $tenancy;

    /** The registered tree, checked; null with no tenancy, or tenants side by side. */
    private readonly ?TenantBounds $tenants;

    /** The form a moment is written in on this connection's database. */
    private readonly TimestampStorage $timestampStorage;

    /**
     * @param TenantSubtrees|null $tenants the tenancy registered with this Orm: the
     *        application's tenant tree, as tinvar/tenancy hands it over. Null for
     *        none, and then every read or write of a #[TenantScoped] model throws
     *        NoTenantContext, whatever tenant is in force. Its answers are read
     *        through TenantBounds, so the limits of a walk -- a loop or more than
     *        32 levels says "not under", a subtree read at once of more than
     *        10,000 tenants throws -- hold whatever an implementation returns
     *        (ADRs 0056, 0196).
     * @param (\Closure(): \DateTimeImmutable)|null $clock what now() answers: the time a
     *        new row's createdAt, and a written row's updatedAt, are stamped with.
     *        Carbon's now(), in UTC, unless given -- which is the system's time, or
     *        the clock a test has Carbon follow (CarbonClock).
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap = new IdentityMap(),
        ?TenantSubtrees $tenants = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->timestampStorage = TimestampStorage::for($connection);
        $this->tenancy = $tenants !== null;
        $this->tenants = $tenants !== null && $tenants->hierarchical() ? new TenantBounds($tenants) : null;
        $this->unscopedByFiber = new \WeakMap();
        $this->readOnlyByFiber = new \WeakMap();
        $this->subtreeByFiber = new \WeakMap();
        $this->subtreeGrantByFiber = new \WeakMap();
        $this->mainFrames = new TransactionFrames();
        $this->framesByFiber = new \WeakMap();

        // The database's half of failing closed (RowLevelSecurity): the
        // connection asks, before each statement, which tenant the fiber
        // running it is in -- the same per-fiber answer the ORM's own filter
        // reads, so the two layers can never disagree about whose rows a
        // statement may see. One Orm per connection: a second one built over
        // it takes the connection over. With no tenancy registered it is told
        // no tenant, never lifted, whatever the Orm's context says: raw SQL
        // around an Orm that reads no tenant's rows reads none either.
        if ($connection instanceof TenantSetting) {
            $connection->followTenant(fn (): array => $this->tenancy
                ? [$this->identityMap->tenant(), $this->isUnscoped(), $this->subtreeInForce() ?? []]
                : ['', false, []]);
        }

        // And each statement shown to the Orm before it is sent: raw SQL
        // that may write inside a widened read names what it may reach, or
        // is refused (guardStatement(), ADR 0122). On the connection, not on
        // what connection() hands out, so one held from anywhere is guarded.
        if ($connection instanceof GuardsStatements) {
            $connection->guardStatements($this->guardStatement(...));
        }
    }

    /**
     * Records each change to an #[Audited] model with $changes (ADR 0156):
     * the application's audit log, which registers itself here when it is
     * built. Null records nothing, and an audited model is then refused at
     * its first write rather than written unrecorded.
     */
    public function recordChangesWith(?ModelChanges $changes): void
    {
        $this->changes = $changes;
    }

    /** Whether changes to #[Audited] models are recorded anywhere. */
    public function recordsChanges(): bool
    {
        return $this->changes !== null;
    }

    /**
     * Refuses a write of an #[Audited] model with nowhere to record it:
     * failing closed, since a change made unrecorded is the one an audit
     * log exists to catch.
     *
     * @internal for the query builder's writes too
     */
    public function refuseUnrecorded(ModelMeta $meta): void
    {
        if ($meta->audited !== null && $this->changes === null) {
            throw new \LogicException(
                "{$meta->class} is #[Audited], and this Orm records changes nowhere, so it is not written. Build the application's "
                . 'audit log over this Orm (the framework\'s AuditLog, ADR 0156), which registers itself with recordChangesWith(), '
                . 'or take #[Audited] off the model.',
            );
        }
    }

    /**
     * Records a change to an #[Audited] model's row, from the row as it was
     * and as it is -- each column => value as the database stores it --
     * inside the transaction the write is in. Nothing for a model that is
     * not audited, nor for an update that changed nothing recorded.
     *
     * @internal for the query builder's writes too
     * @param array<string, mixed>|null $before null for a row created
     * @param array<string, mixed>|null $after null for a row deleted
     * @param list<string>|null $columns the columns an update set; null for every one
     */
    public function recordChange(ModelMeta $meta, ModelChangeKind $kind, string $id, ?array $before, ?array $after, ?array $columns = null): void
    {
        if ($meta->audited === null) {
            return;
        }
        $this->refuseUnrecorded($meta);
        \assert($this->changes !== null);

        $changes = $meta->audited->diff($before, $after, $kind === ModelChangeKind::Updated ? $columns : null);
        if ($kind === ModelChangeKind::Updated && $changes === []) {
            return;
        }

        // The row's own tenant: what a write stamps, or what the row was
        // read with. A write held to the tenant in force reaches no other
        // tenant's row, so that tenant is the row's; one widened past it --
        // withinSubtree(), acrossTenants() -- may reach a child's, or
        // anyone's, and its change is the row's tenant's to see, not the
        // tenant in force's (ADR 0106): refused rather than misfiled.
        $tenant = null;
        if ($meta->tenantColumn !== null) {
            $held = $after[$meta->tenantColumn] ?? $before[$meta->tenantColumn] ?? null;
            if (\is_string($held) && $held !== '') {
                $tenant = $held;
            } elseif ($this->isWidened()) {
                throw new \LogicException(
                    "{$meta->class}: a change to a row reached inside withinSubtree() or acrossTenants() is recorded under the row's "
                    . 'own tenant, and this one did not say which that is.',
                );
            } else {
                $tenant = $this->tenant();
            }
        }

        $this->changes->record(new ModelChange($kind, $meta->class, $id, $tenant, $changes));
    }

    /**
     * The tenant column of a model's row, read from the database, where
     * recordChange() would otherwise not know it: a #[TenantScoped] model
     * need not map its tenant column, so a model's snapshot carries it only
     * when the ORM stamped it on insert -- and a write widened past the
     * tenant in force may reach another tenant's row. Asked only then;
     * empty where the snapshot says, or the tenant in force is the row's.
     *
     * @param array<string, mixed>|null $snapshot the row as the model was read, column => value
     * @return array<string, string> the tenant column => the row's tenant, or nothing
     */
    private function rowTenantFor(ModelMeta $meta, ?array $snapshot, string $id): array
    {
        $column = $meta->tenantColumn;
        if ($column === null || \is_string($snapshot[$column] ?? null) || !$this->isWidened()) {
            return [];
        }

        $rows = $this->connection->select(
            sprintf('SELECT "%s" AS "tenant" FROM "%s" WHERE "%s" = ?', $column, $meta->table, $meta->idColumn),
            [$id],
        );
        $tenant = $rows[0]['tenant'] ?? null;

        return \is_string($tenant) && $tenant !== '' ? [$column => $tenant] : [];
    }

    /**
     * The connection, for raw SQL: what the application sends here carries
     * no tenant predicate of the ORM's. While flagRawStatements() is on, it
     * is handed out through RawStatements, which reports each statement
     * that names a tenant table.
     *
     * A connection that guards its statements (GuardsStatements) shows
     * guardStatement() each one sent from outside the ORM, however it was held:
     * inside withinSubtree() or acrossTenants(), with a tenantsEnteredBy()
     * open, a raw write names the tenants it may have reached, or is
     * refused (ADR 0122).
     */
    public function connection(): Connection
    {
        return $this->rawStatements ?? $this->connection;
    }

    /**
     * A statement about to be sent on the connection, from the guard the
     * Orm installs on one that guards its statements (GuardsStatements).
     *
     * The ORM follows its own writes to the rows they reach and names those
     * rows' tenants (noteRowTenants(), ADR 0106); raw SQL names no rows to
     * follow. So where an announcement is open (tenantsEnteredBy()) and
     * reads are widened, a statement sent from outside the ORM that may
     * write -- every execute(), and a select() RawSql::writes() says may --
     * has what it may have reached named whole, or is refused: failing
     * closed, never announced as the tenant in force alone (ADR 0122):
     *
     *   - inside withinSubtree(), every tenant of the subtree, which is what
     *     the widening let it reach -- on Postgres the policy holds it
     *     there; elsewhere it is what the code asked to reach;
     *   - inside acrossTenants(), every tenant there is, which nothing can
     *     name: refused. Write through the ORM, which names the tenants of
     *     the rows it reaches; or as each tenant, with actAs(); or outside
     *     the widening, where the tenant in force is named.
     *
     * Nothing is asked where nothing would change: no announcement open --
     * a job, a command -- or reads held to the tenant in force.
     */
    private function guardStatement(string $sql, bool $select): void
    {
        if ($this->entered === [] || !$this->isWidened() || self::sentByOrm() || ($select && !RawSql::writes($sql))) {
            return;
        }

        $subtree = $this->subtreeInForce();
        if ($subtree === null) {
            throw new \LogicException(
                'Raw SQL that may write was sent through connection() inside acrossTenants(), where it may reach any tenant\'s rows, and '
                . 'nothing can name them to the caches that hold what it changed. Write through the ORM, which names the tenants of the '
                . 'rows it reaches; as each tenant, with actAs(); or outside acrossTenants(). (' . RawSql::redacted(mb_substr($sql, 0, 120)) . ')',
            );
        }

        foreach ($this->entered as $frame => $tenants) {
            foreach ($subtree as $tenant) {
                if (!\in_array($tenant, $tenants, true)) {
                    $this->entered[$frame][] = $tenant;
                }
            }
        }
    }

    /**
     * Whether the statement the guard is shown was sent by the ORM itself
     * -- the Orm, whose scheduler's loads it runs, and its query builder,
     * named in ORM_WRITERS -- which names the tenants of what it writes.
     * Not by namespace: Schema and the migrations are the ORM's package and
     * pass on SQL their caller wrote, so they are guarded as any caller is.
     * Read off who called the connection's
     * select() or execute(), the outermost connection called (RawStatements
     * is one, over the Orm's). Asked only inside a widened read with an
     * announcement open, so the walk costs nothing elsewhere. Code of the
     * application's own reached from inside the ORM -- a hook -- is its
     * own caller, and is guarded.
     */
    private static function sentByOrm(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
        $caller = null;
        foreach ($frames as $i => $frame) {
            $class = $frame['class'] ?? null;
            if ($class !== null && \in_array($frame['function'], ['select', 'execute'], true) && is_a($class, Connection::class, true)) {
                $caller = $frames[$i + 1]['class'] ?? '';
            }
        }

        return $caller !== null && \in_array($caller, self::ORM_WRITERS, true);
    }

    /**
     * The connection itself, never flagged: for the ORM's own statements
     * and the query builder's, which carry the tenant predicate, and for
     * asking whether something writes on this very connection. Raw SQL
     * goes through connection(); the static check reads it on either.
     */
    public function statements(): Connection
    {
        return $this->connection;
    }

    /**
     * Reports each statement sent through connection() that names the
     * table of a #[TenantScoped] model this worker has compiled -- raw SQL
     * on a tenant table, which tinvar-spec.md, "Tenancy", has flagged in
     * the dev toolbar -- to $flag, before it runs. Null stops it.
     *
     * For dev: it reads every raw statement's text, and finds where it was
     * sent from for each one it reports.
     *
     * @param (\Closure(RawStatement): void)|null $flag
     */
    public function flagRawStatements(?\Closure $flag): void
    {
        if ($flag === null) {
            $this->rawStatements = null;

            return;
        }
        $report = function (string $sql, \Closure $site) use ($flag): void {
            $tables = RawSql::tenantTablesIn($sql, Metadata::tenantTables());
            if ($tables !== []) {
                $flag(new RawStatement(RawSql::redacted($sql), $tables, $site(), $this->identityMap->tenant(), $this->isUnscoped()));
            }
        };
        // Naming the database beneath, where it names one (ADR 0172).
        $this->rawStatements = $this->connection instanceof NamesDriver
            ? new NamedRawStatements($this->connection, $report)
            : new RawStatements($this->connection, $report);
    }

    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * Turns on or off the record of every model a read fills, which
     * readsRecorded() answers with: for the testing layer's model-leak
     * check, which looks for what a request read in what it sent. The
     * identity map is not that record: reads across tenants or a subtree
     * never enter it, and leaving acrossTenants() empties it, so a page
     * reading there was not checked at all. Off unless a test turns it on;
     * flush() empties it.
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function recordReads(bool $on): void
    {
        $this->reads = $on ? ($this->reads ?? []) : null;
        $this->columnReads = $on ? ($this->columnReads ?? []) : null;
    }

    /**
     * Records the strings a read of one column without a model returned,
     * while reads are recorded; nothing otherwise.
     *
     * @param class-string<Model> $model
     * @param list<mixed> $values
     *
     * @internal for the query builder and the testing layer
     */
    public function recordColumnRead(string $model, string $column, array $values): void
    {
        if ($this->columnReads === null) {
            return;
        }
        foreach ($values as $value) {
            if (\is_string($value)) {
                $this->columnReads[] = ['model' => $model, 'column' => $column, 'value' => $value];
            }
        }
    }

    /**
     * What reads of one column without a model returned since recording was
     * turned on, or since the last flush(); none while it is off.
     *
     * @return list<array{model: class-string<Model>, column: string, value: string}>
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function columnReadsRecorded(): array
    {
        return $this->columnReads ?? [];
    }

    /**
     * The models reads have filled since recording was turned on, or since
     * the last flush(); none while it is off.
     *
     * @return list<Model>
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function readsRecorded(): array
    {
        return $this->reads ?? [];
    }

    /**
     * The registered tenant tree, read within its limits: what withinSubtree()
     * reads across, and what the code above the Orm bounds a session's
     * tenant by. Null with no tenancy registered, and for tenants that have
     * no hierarchy.
     */
    public function tenants(): ?TenantBounds
    {
        return $this->tenants;
    }

    /** Whether tenancy was registered with this Orm, so a #[TenantScoped] model can be read. */
    public function hasTenancy(): bool
    {
        return $this->tenancy;
    }

    public function scheduler(): ?BatchScheduler
    {
        return $this->scheduler;
    }

    /**
     * Records every lazy relation load from now on in $loads, or none with
     * null: for a test, never a request. Kept across flush(), which is a
     * request's reset, since a test records across its requests.
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function recordLazyLoads(?LazyLoads $loads): void
    {
        $this->lazyLoads = $loads;
    }

    /**
     * Tells $flag of every lazy relation load from now on, with the site
     * that touched the relation (LazyLoad::$site) -- tinvar-spec.md,
     * "Observability (dev toolbar)": "Every lazy relation load with its
     * trigger site". Null stops it. For dev: it takes a backtrace per load.
     *
     * @param (\Closure(LazyLoad): void)|null $flag
     */
    public function flagLazyLoads(?\Closure $flag): void
    {
        $this->lazyLoadFlag = $flag;
    }

    /**
     * Tells $flag of every acrossTenants() call from now on, with where it
     * was made, before its work runs: "`acrossTenants()` calls flagged"
     * (tinvar-spec.md, "Observability (dev toolbar)"). Null stops it.
     *
     * @param (\Closure(string): void)|null $flag given the call's site, as file:line
     */
    public function flagAcrossTenants(?\Closure $flag): void
    {
        $this->acrossTenantsFlag = $flag;
    }

    /**
     * Tells $observer of every row written from now on -- a save(), a
     * delete(), and each row an update(), delete(), insert() or upsert()
     * statement of the query builder wrote -- with the model's class, the
     * row's key, and whether it was deleted.
     *
     * Every owner's observer is told, in the order they first registered:
     * search and whatever comes next each hear of each write,
     * and one never silences another. (The audit log is not one: it hears of
     * each change inside its transaction, with the change, through
     * recordChangesWith().) $owner names whose it is -- the
     * Search, say -- so that owner registering again replaces only its own
     * (never told twice), and null takes only its own away.
     *
     * An observer that throws fails the write, as anything inside it does,
     * and the observers after it are not told.
     *
     * Told as the write is made, inside whatever transaction it is in, so
     * an observer that tells anyone else waits for the commit with
     * afterCommit(), as search's index does (ADR 0154): a write rolled back
     * then reaches nobody. Kept across flush(), which is a request's reset:
     * it is the application's wiring, not the request's state.
     *
     * @param (\Closure(class-string<Model>, string, bool): void)|null $observer
     */
    public function observeWrites(object $owner, ?\Closure $observer): void
    {
        $key = spl_object_id($owner);
        if ($observer === null) {
            unset($this->writeObservers[$key]);

            return;
        }
        $this->writeObservers[$key] = [$owner, $observer];
    }

    /**
     * Where the code that called into the ORM is: the first frame of the
     * stack outside this package's ORM, as file:line; '' when there is none.
     */
    private static function callSite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file !== null && !str_starts_with($file, __DIR__ . \DIRECTORY_SEPARATOR) && isset($frame['line'])) {
                return "{$file}:{$frame['line']}";
            }
        }

        return '';
    }

    /**
     * Where lazy relation loads are being recorded, or null when they are not.
     *
     * @internal for the testing layer (tinvar/testing), released with this
     *     package at one version; not for applications
     */
    public function lazyLoads(): ?LazyLoads
    {
        return $this->lazyLoads;
    }

    /** Installs a scheduler for the duration of one render, enabling Level 2. */
    public function beginRender(): BatchScheduler
    {
        return $this->scheduler = new BatchScheduler($this);
    }

    public function endRender(): void
    {
        $this->scheduler = null;
    }

    /**
     * Reset at every request, job and tenant boundary.
     *
     * This is the ORM's half of the reset contract the request scope keeps,
     * and the spec's clause starts with the tenant: "Tenant, auth, panel,
     * identity map and #[Scoped] services reset at the end of each request
     * and job."
     * It used to clear the caches and leave the CONTEXT -- tenant, unscoped
     * depth, read-only depth -- exactly as the last request left it. actAs()
     * restores in a finally so the usual path was safe, but switchTenant() is
     * public, and a tenant carried into the next request on a worker is the
     * same class of bug as a user carried into it.
     */
    public function flush(): void
    {
        $this->identityMap->flush();
        $this->relationRows = [];
        $this->scheduler = null;

        $this->identityMap->resetTenant();
        $this->unscopedMain = 0;
        $this->unscopedByFiber = new \WeakMap();
        $this->readOnlyMain = 0;
        $this->readOnlyByFiber = new \WeakMap();
        $this->subtreeMain = null;
        $this->subtreeByFiber = new \WeakMap();
        $this->subtreeGrantMain = null;
        $this->subtreeGrantByFiber = new \WeakMap();
        $this->entered = [];
        if ($this->reads !== null) {
            $this->reads = [];
        }
        if ($this->columnReads !== null) {
            $this->columnReads = [];
        }

        // A transaction still open here was left in a fiber nobody resumes.
        // Its frames would hold the next request's afterCommit() callbacks
        // for a commit that never comes, and fold its undo into theirs.
        $this->mainFrames = new TransactionFrames();
        $this->framesByFiber = new \WeakMap();

        // And the database's copy, now rather than at the next statement:
        // anything reaching the connection around this class before then
        // -- a raw PDO handle -- must not find the last request's tenant.
        if ($this->connection instanceof TenantSetting) {
            $this->connection->clearTenantSetting();
        }
    }

    /**
     * The only supported way to change tenant.
     *
     * The relation-row cache is keyed by relation and parent id, not by tenant,
     * so it has to be dropped here or rows loaded for one tenant would answer a
     * relation read for the next. Tenancy fails closed in this framework; a
     * cache that silently crosses the boundary would undo that.
     */
    public function switchTenant(string $tenant): void
    {
        self::assertTenantName($tenant);
        if ($tenant === $this->identityMap->tenant()) {
            return;
        }

        foreach ($this->entered as $frame => $tenants) {
            if (!\in_array($tenant, $tenants, true)) {
                $this->entered[$frame][] = $tenant;
            }
        }

        // The relation-row cache is namespaced by tenant (relationCacheKey),
        // so a switch cannot read across one -- and the scheduler is left
        // alone. Nulling it here meant any actAs() inside a render silently
        // dropped every later relation load in that render from Level 2
        // batching to Level 1, with no diagnostic.
        //
        // Namespacing is not the same as freshness, though. The entered
        // tenant's relation rows are dropped alongside its identity-map
        // buckets, for the reason the map does it: a switch BACK must not
        // serve rows cached before an intervening write. And, as the map
        // does, only on a switch the main context makes: these rows are
        // shared by every fiber in a render.
        if (\Fiber::getCurrent() === null) {
            $this->forgetRelationRows($tenant);
        }
        $this->identityMap->switchTenant($tenant);
    }

    public function tenant(): string
    {
        return $this->identityMap->tenant();
    }

    /**
     * A tenant's name is printable text: what a Postgres setting can carry,
     * never the claim a token minted with no tenancy carries, which is
     * spelled with a control character, and with no space at either end,
     * which MySQL would compare away (TenantBounds::isName()).
     */
    private static function assertTenantName(string $tenant): void
    {
        if ($tenant !== '' && !TenantBounds::isName($tenant)) {
            throw new \InvalidArgumentException('A tenant\'s name has no control character in it, nor a space at either end.');
        }
    }

    /**
     * Runs $work, and answers what it returned with every tenant it ran as:
     * the tenant in force, first, and each one it switched into -- actAs(),
     * a job's or a webhook's tenant -- in the order it first did.
     *
     * For the tags an action invalidates (ActionRunner). ADR 0047 has code
     * write a child's row by actAs()ing the child, and the write is the
     * child's: announced as the tenant the action ran as alone, the child's
     * own pages and cached queries were never told. A tenant entered only to
     * read is told too -- a refetch for nothing, which is waste, not a leak:
     * an update reaches only the subscribers a render in that tenant allowed.
     *
     * A write made inside withinSubtree() or acrossTenants() is made as the
     * tenant in force, but the rows an update or a delete there reaches may
     * be a child's, or anyone's: each such row's own tenant is named too
     * (noteRowTenants(), ADR 0106), so the child's pages and cached queries
     * are told of a write to its row as they are when the write actAs()es
     * it. An insert is stamped with the tenant in force, already named.
     *
     * @template T
     * @param \Closure(): T $work
     * @return array{T, non-empty-list<string>}
     */
    public function tenantsEnteredBy(\Closure $work): array
    {
        $frame = \count($this->entered);
        $this->entered[$frame] = [$this->tenant()];

        try {
            $result = $work();

            // Emptied under it by flush() -- the request ended around it --
            // and then the tenant in force is all there is to name.
            return [$result, $this->entered[$frame] ?? [$this->tenant()]];
        } finally {
            if (\count($this->entered) > $frame) {
                array_splice($this->entered, $frame);
            }
        }
    }

    /**
     * Names, for every tenantsEnteredBy() open, the tenants the rows of
     * $meta keyed $ids are stored in -- before a delete removes them, or
     * after an update wrote them -- where the write reached past the tenant
     * in force: inside withinSubtree() or acrossTenants() (ADR 0106).
     *
     * Asked of the database rather than the models: a statement-level
     * update or delete reads no model, and a model need not map its tenant
     * column. Nothing is asked where nothing would change -- no frame open,
     * no tenant column, or a write held to the tenant in force, which is
     * named already -- so the narrow write, the common one, costs nothing.
     *
     * @param list<string> $ids
     *
     * @internal for the Orm and its query builder, inside this package
     */
    public function noteRowTenants(ModelMeta $meta, array $ids): void
    {
        if ($this->entered === [] || $meta->tenantColumn === null || $ids === [] || !$this->isWidened()) {
            return;
        }

        foreach (array_chunk($ids, 1_000) as $chunk) {
            $rows = $this->connection->select(
                sprintf(
                    'SELECT DISTINCT "%s" AS "tenant" FROM "%s" WHERE "%s" IN (%s)',
                    $meta->tenantColumn,
                    $meta->table,
                    $meta->idColumn,
                    implode(', ', array_fill(0, \count($chunk), '?')),
                ),
                $chunk,
            );
            foreach ($rows as $row) {
                $tenant = $row['tenant'] ?? null;
                if (!\is_string($tenant) || $tenant === '') {
                    continue;
                }
                foreach ($this->entered as $frame => $tenants) {
                    if (!\in_array($tenant, $tenants, true)) {
                        $this->entered[$frame][] = $tenant;
                    }
                }
            }
        }
    }

    /**
     * Runs $work as $tenant, restoring the previous context afterwards.
     *
     * "Scoped switching only ... take a closure and restore the previous
     * context afterwards." A bare setter would leak a switch into whatever ran
     * next on the same worker.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function actAs(string $tenant, \Closure $work): mixed
    {
        // Refused before anything below changes: the scope lifted and the
        // subtree dropped here would otherwise stay so for the rest of the
        // work around this call, with no finally to put them back.
        self::assertTenantName($tenant);

        $previous = $this->identityMap->tenant();
        $previousUnscoped = $this->unscopedDepth();
        $previousSubtree = $this->subtreeState();

        // An explicit switch wins over an enclosing acrossTenants(). Without
        // this, actAs() inside acrossTenants() would name a tenant and still
        // read every tenant's rows, which is the opposite of what it says.
        // And over an enclosing withinSubtree(), for the same reason: the
        // tenant named is the tenant read.
        $this->setUnscopedDepth(0);
        $this->setSubtreeState(null);
        $this->switchTenant($tenant);

        // Entering a scoped context from an unscoped one: drop anything the
        // unscoped read may have left reachable, in both directions.
        if ($previousUnscoped > 0) {
            $this->identityMap->flush();
            $this->relationRows = [];
        }

        try {
            return $work();
        } finally {
            $this->switchTenant($previous);
            $this->setUnscopedDepth($previousUnscoped);
            $this->setSubtreeState($previousSubtree);
            if ($previousUnscoped > 0) {
                $this->identityMap->flush();
                $this->relationRows = [];
            }
        }
    }

    /**
     * Lifts tenant scoping for the duration of $work.
     *
     * The escape hatch the spec requires to be explicit and flagged by the dev
     * toolbar. Nesting is counted so an inner call cannot re-enable scoping
     * for an outer one.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acrossTenants(\Closure $work): mixed
    {
        if ($this->acrossTenantsFlag !== null) {
            ($this->acrossTenantsFlag)(self::callSite());
        }
        $this->setUnscopedDepth($this->unscopedDepth() + 1);

        try {
            return $work();
        } finally {
            $this->setUnscopedDepth(max(0, $this->unscopedDepth() - 1));
            // The map is cleared on exit: rows read unscoped must not be
            // served to a later scoped read.
            $this->identityMap->flush();
            $this->relationRows = [];
        }
    }

    /**
     * Reads, for the duration of $work, the rows of the tenant in force and
     * of every tenant below it: a reseller's own and its customers'.
     *
     * The spec's `withinReseller()`, named for what it reads rather than for
     * one application's tenant kinds. "Widening (a reseller's customers, all
     * tenants for staff) comes from the session's membership: its tenant
     * kind plus a panel permission" -- so, unlike acrossTenants(), it runs
     * only where a membership granted it (grantSubtree()): a panel defined
     * with `subtree: true`, after its membership check. Called anywhere
     * else it throws, so a page that widens by mistake fails rather than
     * reads.
     *
     * The tenant in force does not change. A row written here is stamped
     * with it, a job dispatched here runs as it, and every token minted here
     * -- route, fragment, upload -- names it, exactly: what widens is the
     * predicate, `tenant IN (subtree)`, and the policy Postgres holds the
     * connection to (RowLevelSecurity), never the tenant anything is bound
     * to. To write a child's row as the child, actAs() it.
     *
     * Reads here bypass the identity map and the relation-row cache, as
     * unscoped ones do: a child's row read here and cached under the parent
     * would answer a later read made as the parent alone. actAs() inside
     * narrows to the tenant it names; acrossTenants() inside lifts scoping
     * altogether, as it does anywhere.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     * A subtree of up to TenantBounds::MAX_SUBTREE tenants, root included,
     * is read at once, bound as one value past MAX_LISTED; a larger one is
     * refused, saying to read it a page at a time with withinSubtreePages()
     * -- never read in part (ADR 0196).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws \LogicException where no membership granted it, with no tenant tree, or no tenant
     * @throws \LengthException for a subtree larger than TenantBounds::MAX_SUBTREE
     */
    public function withinSubtree(\Closure $work): mixed
    {
        [$root, $tree] = $this->grantedRoot('withinSubtree()');

        return $this->inSubtree([$root, $tree->subtree($root)], $work);
    }

    /**
     * withinSubtree() a page at a time: $work runs once for each page of
     * the subtree of the tenant in force -- $size tenants, in byte order of
     * their names, the root among them where its name sorts -- with reads
     * widened to that page's tenants alone (ADR 0196).
     *
     * For a subtree of any size, larger than withinSubtree() reads at once.
     * Allowed exactly where withinSubtree() is -- a membership's grant, from
     * the tenant it was held in or one below it -- and everything else is
     * as it is there: the tenant in force does not change, a row written is
     * stamped with it, a token minted names it, and the predicate and
     * Postgres's policy are widened to the page and no further.
     *
     * Each page is read from the tree as the walk reaches it, and $work runs
     * outside any transaction this opens. $after goes on from a page's
     * last() -- a checkpoint -- and $pages stops after so many; the answer
     * is the last tenant of the last page run, to go on after, or null once
     * the subtree has been read to its end.
     *
     * @param \Closure(SubtreePage): mixed $work
     * @param int $size tenants to a page, from 1 to TenantBounds::MAX_SUBTREE
     * @param int|null $pages how many pages to run before stopping, 1 or more; all of them for null
     * @return string|null where to go on after, or null at the end
     * @throws \LogicException where withinSubtree() would be refused
     */
    public function withinSubtreePages(\Closure $work, int $size = TenantBounds::MAX_LISTED, ?string $after = null, ?int $pages = null): ?string
    {
        if ($pages !== null && $pages < 1) {
            throw new \InvalidArgumentException('A walk that stops runs a page at least.');
        }
        [$root, $tree] = $this->grantedRoot('withinSubtreePages()');

        $run = 0;
        foreach ($tree->pages($root, $size, $after) as $page) {
            $this->inSubtree([$root, $page->tenants], static fn (): mixed => $work($page));
            if ($pages !== null && ++$run >= $pages) {
                return $page->last();
            }
        }

        return null;
    }

    /**
     * Runs $work as each tenant of $root's subtree in turn -- $root among
     * them where its name sorts -- for a job or a command that walks one
     * (ADR 0196). Each run is actAs() that tenant: its rows alone, a write
     * stamped with it, nothing widened, as code may run as any tenant it
     * names. So no membership's grant is asked for, and none is given; a
     * job holds none (ADR 0047).
     *
     * The subtree is read a page of $pageSize at a time, in byte order of
     * the tenants' names, and holds no bound: only a page is held. $after
     * goes on after a tenant -- the one the last run stopped at, which is
     * the checkpoint a long job keeps -- and $limit stops after so many
     * tenants. The answer is the last tenant run, to go on after, or null
     * once the subtree has been walked to its end. Nothing is held across
     * tenants: a transaction is $work's to open, for its tenant.
     *
     * @param \Closure(string): mixed $work given the tenant it runs as
     * @param int|null $limit how many tenants to run before stopping, 1 or more; all of them for null
     * @return string|null where to go on after, or null at the end
     * @throws \LogicException with no tenant tree
     */
    public function forEachTenant(string $root, \Closure $work, ?string $after = null, ?int $limit = null, int $pageSize = TenantBounds::PAGE): ?string
    {
        if ($this->tenants === null) {
            throw new \LogicException('forEachTenant() walks a tenant\'s subtree, and the Orm was given no tenant tree.');
        }
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('A walk that stops runs a tenant at least.');
        }

        $run = 0;
        foreach ($this->tenants->pages($root, $pageSize, $after) as $page) {
            foreach ($page->tenants as $tenant) {
                $this->actAs($tenant, static fn (): mixed => $work($tenant));
                if ($limit !== null && ++$run >= $limit) {
                    return $tenant;
                }
            }
        }

        return null;
    }

    /**
     * Refuses a new row of $class inside a page of withinSubtreePages() that
     * does not hold the tenant in force (ADR 0196). The row is stamped with
     * that tenant, which the page does not read: it would not be read back
     * where it was made, nor indexed for search under its tenant. A page
     * that holds the tenant in force, and withinSubtree(), whose subtree
     * always does, make new rows as anywhere.
     *
     * @internal for the Orm and its query builder, inside this package
     */
    public function refuseNewRowUnread(string $class): void
    {
        $subtree = $this->subtreeInForce();
        if ($subtree !== null && !\in_array($this->tenant(), $subtree, true)) {
            throw new \LogicException(
                "{$class}: a new row is stamped with the tenant in force, '{$this->tenant()}', which this page of withinSubtreePages() "
                . 'does not read. Make it in the page that holds that tenant, or as the tenant it belongs to, with actAs().',
            );
        }
    }

    /**
     * The tenant in force, where a membership's grant lets $what read below
     * it, and the tree to read: refused anywhere else.
     *
     * @return array{string, TenantBounds}
     */
    private function grantedRoot(string $what): array
    {
        $granted = $this->subtreeGrant();
        if ($granted === null) {
            throw new \LogicException(
                "{$what} reads below the tenant in force, which only a membership grants: run it in a panel "
                . 'defined with `subtree: true`, whose membership check grants it.',
            );
        }
        if ($this->tenants === null) {
            throw new \LogicException("{$what} reads across a tenant's subtree, and the Orm was given no tenant tree.");
        }

        $root = $this->tenant();
        if ($root === '') {
            throw new \LogicException("{$what} reads below the tenant in force, and there is none.");
        }

        // The grant is the membership's, held in one tenant: it reaches that
        // tenant's subtree, from it or from a tenant below it, and no other.
        // actAs() into a tenant elsewhere reads that tenant -- and widens
        // nothing there.
        if ($root !== $granted && !$this->tenants->contains($granted, $root)) {
            throw new \LogicException(
                "{$what} was granted below '{$granted}', and the tenant in force is not in its subtree.",
            );
        }

        return [$root, $this->tenants];
    }

    /**
     * Runs $work with withinSubtree() allowed across the subtree of the
     * tenant in force now: the grant a membership held in it gives.
     *
     * Called by the panel stack, after the membership check, for a panel
     * whose definition says its memberships reach the subtree; anything
     * else calling it says the same thing, as explicitly as a call to
     * acrossTenants() would. Scoped, and inherited by the render's fibers.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function grantSubtree(\Closure $work): mixed
    {
        // No tenant, nothing to reach below: granted nowhere.
        $tenant = $this->tenant();

        return $this->withSubtreeGrant($tenant === '' ? null : $tenant, $work);
    }

    /**
     * Runs $work with no subtree granted, whatever granted one around it.
     *
     * For a job (JobRunner), which ADR 0047 never grants the subtree: a
     * SyncQueue runs one inside the request that dispatched it, and it must
     * not read across the subtree that request's panel granted -- a job runs
     * as the tenant it was dispatched in, and no wider, wherever it runs.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function withoutSubtreeGrant(\Closure $work): mixed
    {
        return $this->withSubtreeGrant(null, $work);
    }

    /**
     * @template T
     * @param string|null $tenant whose subtree is granted, or null for none
     * @param \Closure(): T $work
     * @return T
     */
    private function withSubtreeGrant(?string $tenant, \Closure $work): mixed
    {
        $fiber = \Fiber::getCurrent();
        $had = $fiber !== null && isset($this->subtreeGrantByFiber[$fiber]);
        $previous = $fiber === null ? $this->subtreeGrantMain : ($had ? $this->subtreeGrantByFiber[$fiber] : null);

        // A fiber's "none" is kept as '', not as no entry: with no entry it
        // would inherit the main context's grant, which is what is withheld.
        if ($fiber === null) {
            $this->subtreeGrantMain = $tenant;
        } else {
            $this->subtreeGrantByFiber[$fiber] = $tenant ?? '';
        }

        try {
            return $work();
        } finally {
            if ($fiber === null) {
                $this->subtreeGrantMain = $previous;
            } elseif ($previous !== null) {
                $this->subtreeGrantByFiber[$fiber] = $previous;
            } else {
                unset($this->subtreeGrantByFiber[$fiber]);
            }
        }
    }

    /** Whether a membership granted withinSubtree() to the work running now. */
    public function isSubtreeGranted(): bool
    {
        return $this->subtreeGrant() !== null;
    }

    /** The tenant whose subtree a membership granted the work running now, or null. */
    private function subtreeGrant(): ?string
    {
        $fiber = \Fiber::getCurrent();
        $grant = $fiber === null ? $this->subtreeGrantMain : ($this->subtreeGrantByFiber[$fiber] ?? $this->subtreeGrantMain);

        return $grant === '' ? null : $grant;
    }

    /**
     * The tenants a read now reaches when withinSubtree() has widened it --
     * the tenant in force first -- or withinSubtreePages() has, to a page
     * that need not hold the tenant in force; null when it reads one
     * tenant, or scoping is lifted.
     *
     * @return non-empty-list<string>|null
     */
    public function subtreeInForce(): ?array
    {
        if ($this->unscopedDepth() > 0) {
            return null;
        }

        $subtree = $this->subtreeState();

        // Its root is the tenant in force, or it is not in force: a bare
        // switchTenant() inside the block reads the tenant it switched to,
        // alone, rather than that tenant beside another's subtree.
        return $subtree !== null && $subtree[0] === $this->tenant() ? $subtree[1] : null;
    }

    /**
     * Whether reads now reach past the tenant in force -- acrossTenants()
     * or withinSubtree() -- and so bypass the identity map and the
     * relation-row cache, which hold the tenant's rows alone.
     */
    public function isWidened(): bool
    {
        return $this->isUnscoped() || $this->subtreeInForce() !== null;
    }

    /**
     * $work inside a subtree already worked out: for withinSubtree(), and
     * for work deferred from inside one (captureScope()), which goes on
     * reading the subtree it was set up in without asking for it again.
     *
     * @template T
     * @param array{string, non-empty-list<string>} $subtree
     * @param \Closure(): T $work
     * @return T
     */
    private function inSubtree(array $subtree, \Closure $work): mixed
    {
        $previous = $this->subtreeState();
        $previousUnscoped = $this->unscopedDepth();

        // Named, so it narrows an enclosing acrossTenants() as actAs() does.
        $this->setUnscopedDepth(0);
        $this->setSubtreeState($subtree);

        try {
            return $work();
        } finally {
            $this->setSubtreeState($previous);
            $this->setUnscopedDepth($previousUnscoped);
        }
    }

    /** @return array{string, non-empty-list<string>}|null this fiber's own, never main's */
    private function subtreeState(): ?array
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? $this->subtreeMain : ($this->subtreeByFiber[$fiber] ?? null);
    }

    /** @param array{string, non-empty-list<string>}|null $subtree */
    private function setSubtreeState(?array $subtree): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->subtreeMain = $subtree;
        } elseif ($subtree === null) {
            unset($this->subtreeByFiber[$fiber]);
        } else {
            $this->subtreeByFiber[$fiber] = $subtree;
        }
    }

    /**
     * Runs $work with writes refused.
     *
     * "Loaders and queries run in a read-only transaction. save(), job
     * dispatch and event dispatch throw inside them, because hovering a link
     * runs them." Prefetch-on-hover is the point: a loader runs for a page
     * nobody has chosen to visit, so a write there happens without anyone
     * deciding to.
     *
     * Refused twice. In PHP first: save(), delete() and transaction() throw
     * before reaching the database, naming the call. Then by the database:
     * $work runs in the connection's read-only transaction (ADR 0022), so a
     * write sent around the facade -- `$orm->connection()->execute()`, raw
     * PDO -- is refused as well. Job and event dispatch, which the spec names
     * in the same breath, do not exist yet.
     *
     * The two are kept at different grains on purpose. The PHP check is per
     * fiber, as the tenant is: one fiber's read-only context must not make
     * another's code throw. The database's is per connection, since a
     * connection holds one transaction: while any fiber is inside a scope,
     * every statement on the connection runs in it. A render runs only
     * read-only fibers, so they share it without waiting on one another; a
     * write from a fiber outside every scope, while another is suspended
     * inside one, is refused by the database alone.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function readOnly(\Closure $work): mixed
    {
        $this->setReadOnlyDepth($this->readOnlyDepth() + 1);

        try {
            // A Lazy made in here is resolved later, by the encoder, after
            // this has returned -- so it takes the read-only scope with it.
            // Otherwise a Lazy::of() closure in a loader's Data, or a mapped
            // lazy over an array source, could write during a GET.
            return $this->connection->readOnly(
                fn (): mixed => Lazy::within(fn (\Closure $deferred): mixed => $this->readOnly($deferred), $work),
            );
        } finally {
            $this->setReadOnlyDepth(max(0, $this->readOnlyDepth() - 1));
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyDepth() > 0;
    }

    /**
     * The read scope in force now -- tenant, the subtree withinSubtree()
     * widened it to, read-only or not -- as a closure that runs work under
     * it later. An unscoped context is refused.
     *
     * For work deferred past the code that set the scope up: a Lazy field
     * mapped inside actAs('acme') is read when the encoder resolves it, by
     * which point the tenant may be another, or scoping lifted.
     *
     * @return \Closure(\Closure(): mixed): mixed
     */
    public function captureScope(): \Closure
    {
        // Never an unscoped one: acrossTenants() is an escape hatch the spec
        // requires to be explicit, and work that re-entered it later would
        // lift tenant scoping outside the block that opened the hatch.
        if ($this->isUnscoped()) {
            throw new \LogicException(
                'An unscoped read cannot be deferred: do the work inside acrossTenants(), not after it.',
            );
        }

        $tenant = $this->tenant();
        $readOnly = $this->isReadOnly();
        $subtree = $this->subtreeInForce();

        return function (\Closure $work) use ($tenant, $readOnly, $subtree): mixed {
            $run = $readOnly
                ? fn (): mixed => $this->readOnly(static fn (): mixed => $work())
                : static fn (): mixed => $work();

            // Set up inside withinSubtree(): read across the same subtree,
            // as it was when the work was set up -- a membership granted it
            // then, and this is the block's own work, deferred. Inside
            // actAs(), which starts from the tenant alone.
            if ($subtree !== null) {
                $exact = $run;
                $run = fn (): mixed => $this->inSubtree([$tenant, $subtree], $exact);
            }

            return $this->actAs($tenant, $run);
        };
    }

    private function readOnlyDepth(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? $this->readOnlyMain : ($this->readOnlyByFiber[$fiber] ?? $this->readOnlyMain);
    }

    private function setReadOnlyDepth(int $depth): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->readOnlyMain = $depth;

            return;
        }

        $this->readOnlyByFiber[$fiber] = $depth;
    }

    public function isUnscoped(): bool
    {
        return $this->unscopedDepth() > 0;
    }

    /** Scoping state for the fiber that is asking, or for the main context. */
    private function unscopedDepth(): int
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $this->unscopedMain;
        }

        return $this->unscopedByFiber[$fiber] ?? 0;
    }

    private function setUnscopedDepth(int $depth): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->unscopedMain = $depth;

            return;
        }

        $this->unscopedByFiber[$fiber] = $depth;
    }

    /**
     * The tenant predicate for a model, and its binding: the tenant in
     * force, or every tenant in the subtree withinSubtree() widened to.
     *
     * $alias qualifies the column, for a statement that reads two tables:
     * a #[BelongsToMany]'s pivot joined to its target (ADR 0192).
     *
     * @return array{string, list<string>}
     */
    private function tenantScope(ModelMeta $meta, ?string $alias = null): array
    {
        $tenants = $this->tenantsInScope($meta);
        if ($tenants === null || $meta->tenantColumn === null) {
            return ['', []];
        }
        $column = ($alias === null ? '' : '"' . $alias . '".') . '"' . $meta->tenantColumn . '"';

        return $this->tenantIn($column, $tenants);
    }

    /**
     * The most keys one statement lists, `IN (?, ...)`, on this connection:
     * Query::MAX_VALUES, and 2,000 on the async MySQL driver, whose client
     * (amphp/mysql) sends no statement of more than about 3,600 bound values
     * -- 2,000 leaves room for the tenant predicate's list beside them (ADR
     * 0196, "What a review found"). Reads by key, relation loads and writes
     * by key take this many at a time.
     *
     * @internal for the Orm and its query builder, inside this package
     * @return positive-int
     */
    public function maxValues(): int
    {
        return $this->onAsyncMySql() ? 2_000 : Query::MAX_VALUES;
    }

    /**
     * The most values one INSERT binds on this connection: Query::MAX_BINDINGS,
     * and 3,000 on the async MySQL driver, for the reason maxValues() gives.
     *
     * @internal for the query builder, inside this package
     * @return positive-int
     */
    public function maxBindings(): int
    {
        return $this->onAsyncMySql() ? 3_000 : Query::MAX_BINDINGS;
    }

    private function onAsyncMySql(): bool
    {
        return $this->connection instanceof AsyncConnection && $this->connection->driver() === 'mysql';
    }

    /**
     * `$column` held to $tenants, and its bindings: `= ?` for one, a
     * placeholder each up to TenantBounds::MAX_LISTED, and past that the
     * whole list as one bound value -- a Postgres array, `= ANY (?)`, which
     * Postgres types as the column's; a JSON array MySQL's JSON_TABLE() or
     * SQLite's json_each() reads as a table -- so a statement inside a large
     * subtree is no larger than one inside a small one (ADR 0196).
     *
     * The same list either way, which is the list Postgres's policy is told:
     * the predicate and the policy cannot disagree about whose rows a read
     * reaches. MySQL's column is its type's collation's to compare, as `= ?`
     * is, and a name longer than JSON_TABLE()'s column is an error, never a
     * shorter name. A connection that does not say which database it talks
     * to is refused past the list, rather than sent SQL it may not read.
     *
     * @param non-empty-list<string> $tenants
     * @return array{string, list<string>}
     * @throws \LengthException past MAX_LISTED on a connection that names no driver
     *
     * @internal for the Orm and its query builder (Builder\Compiler), inside this package
     */
    public function tenantIn(string $column, array $tenants): array
    {
        if (\count($tenants) === 1) {
            return [$column . ' = ?', $tenants];
        }
        if (\count($tenants) <= TenantBounds::MAX_LISTED) {
            return [$column . ' IN (' . implode(', ', array_fill(0, \count($tenants), '?')) . ')', $tenants];
        }

        $driver = $this->connection instanceof NamesDriver ? $this->connection->driver() : null;

        return match ($driver) {
            'pgsql' => [$column . ' = ANY (?)', [RowLevelSecurity::arrayLiteral($tenants)]],
            'mysql' => [
                $column . ' IN (SELECT tinvar_subtree.tenant FROM JSON_TABLE(?, \'$[*]\' COLUMNS '
                    . '(tenant VARCHAR(255) PATH \'$\' ERROR ON ERROR)) AS tinvar_subtree)',
                [json_encode($tenants, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
            ],
            'sqlite' => [
                $column . ' IN (SELECT value FROM json_each(?))',
                [json_encode($tenants, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
            ],
            default => throw new \LengthException(\sprintf(
                'A read across %d tenants binds them as one value, and this connection does not say which database it talks to (NamesDriver); '
                . 'read the subtree a page of %d at a time, with withinSubtreePages().',
                \count($tenants),
                TenantBounds::MAX_LISTED,
            )),
        };
    }

    /**
     * What a read of $meta's own table -- named $alias, if the statement
     * names it -- is held to: the tenant predicate,
     * and -- for a #[SoftDeletes] model -- which rows by their trashed
     * moment (ADR 0191). A write is held to the tenant alone
     * (tenantScope()): save() and forceDelete() reach a trashed row.
     *
     * @return array{string, list<string>}
     */
    private function readScope(ModelMeta $meta, Trashed $trashed, ?string $alias = null): array
    {
        [$scope, $bindings] = $this->tenantScope($meta, $alias);
        $predicate = $meta->deletedAtColumn === null
            ? null
            : $trashed->predicate(($alias === null ? '' : '"' . $alias . '".') . '"' . $meta->deletedAtColumn . '"');
        if ($predicate !== null) {
            $scope = $scope === '' ? $predicate : "{$scope} AND {$predicate}";
        }

        return [$scope, $bindings];
    }

    /**
     * The tenants a read of $meta is held to now -- the tenant in force, or
     * every tenant in the subtree withinSubtree() widened to -- or null when
     * nothing holds it: the model is not #[TenantScoped], or acrossTenants()
     * lifted scoping. Throws NoTenantContext where a read must fail closed.
     *
     * The one answer find(), all(), whereIn(), relation loads and the query
     * builder (Builder\Compiler) all predicate on, so the builder cannot
     * scope a read differently from them.
     *
     * And the one search's index reads by (ADR 0154), so a search is held
     * to exactly the tenants a read of the model is.
     *
     * @return non-empty-list<string>|null
     * @throws NoTenantContext with no tenancy registered, or no tenant in force
     */
    public function tenantsInScope(ModelMeta $meta): ?array
    {
        if ($meta->tenantColumn === null) {
            return null;
        }
        // Before acrossTenants() is asked: with no tenancy registered it
        // lifts nothing, since no tenant's rows are anyone's to read.
        if (!$this->tenancy) {
            throw NoTenantContext::noTenancy($meta->class);
        }
        if ($this->unscopedDepth() > 0) {
            return null;
        }

        $tenant = $this->identityMap->tenant();
        if ($tenant === '') {
            throw new NoTenantContext($meta->class);
        }

        // The subtree's tenants, or a page of them (withinSubtreePages()),
        // which need not hold the tenant in force.
        return $this->subtreeInForce() ?? [$tenant];
    }

    /**
     * A query over $class: an immutable builder, read when a terminal method
     * -- fetch(), first(), count(), paginate() -- runs it, under the scope in
     * force then.
     *
     * @template T of Model
     * @param class-string<T> $class
     * @return Query<T>
     */
    #[\NoDiscard]
    public function query(string $class): Query
    {
        return Query::of($this, $class);
    }

    // ---------------------------------------------------------------- reading

    /**
     * Whether a model in the identity map stands for a row that was read.
     *
     * Two things have to be true, and the ORDER matters. Laziness is checked
     * first, and WITHOUT touching the object: isPersisted() reads a property,
     * and reading a property of an uninitialized lazy ghost runs its
     * initializer. So asking "is this a real row?" the obvious way issued the
     * very query the question exists to avoid trusting -- and once a dangling
     * foreign key raises DanglingRelation, it did so from inside what should
     * have been a plain lookup.
     *
     * A ghost is a promise about a foreign key. Only markClean() -- hydrate
     * from a real row, or an insert -- makes a model evidence of one.
     */
    public static function holdsRow(object $model): bool
    {
        if ((new \ReflectionClass($model::class))->isUninitializedLazyObject($model)) {
            return false;
        }

        return $model instanceof Model && $model->isPersisted();
    }

    /**
     * Whether $model is a row of the tenant in force: always, for a model
     * that is not #[TenantScoped]; never, with no tenant in force, or for a
     * model that holds no row. What a job's dispatch holds its models to,
     * since the job runs as that tenant.
     *
     * Exactly the tenant in force, whatever withinSubtree() or
     * acrossTenants() widened reads to: the row as the snapshot says where
     * the model maps its tenant column, and else asked of the database,
     * where Postgres's policy holds the read to the tenant in force too.
     */
    public function isOwnRow(Model $model): bool
    {
        $meta = Metadata::for($model::class);
        if ($meta->tenantColumn === null) {
            return true;
        }
        $tenant = $this->identityMap->tenant();
        if ($tenant === '' || !self::holdsRow($model)) {
            return false;
        }

        $held = $model->snapshot()[$meta->tenantColumn] ?? null;
        if (\is_string($held) && $held !== '') {
            return $held === $tenant;
        }

        return $this->connection->select(
            sprintf('SELECT 1 AS "own" FROM "%s" WHERE "%s" = ? AND "%s" = ?', $meta->table, $meta->idColumn, $meta->tenantColumn),
            [$meta->keyOf($model), $tenant],
        ) !== [];
    }

    /**
     * The row of $class under $id, or null where there is none the scope
     * in force may read. A #[SoftDeletes] model's trashed row is none
     * unless $trashed says so -- the identity map holding it included
     * (ADR 0191).
     *
     * @param class-string<Model> $class
     */
    public function find(string $class, string $id, Trashed $trashed = Trashed::Without): ?Model
    {
        // Every key is a UUIDv7 this Orm minted, so anything else names no
        // row -- and must be answered as such without asking the database.
        // Postgres refuses a malformed value for a uuid column outright, so
        // a route segment like /racks/nope was a 500 rather than a 404, and
        // inside a transaction the refusal would abort the transaction too.
        //
        // After the tenant check, which must fail closed whatever the key.
        if (!Uuid::isValid($id)) {
            $this->tenantScope(Metadata::for($class));

            return null;
        }

        // The identity map is keyed by tenant but knows nothing of scoping, so
        // a row read inside acrossTenants() would otherwise answer a later
        // scoped read for the same id. Unscoped reads bypass the caches
        // entirely: an explicit escape hatch can afford to be uncached.
        // A #[BelongsTo] registers a lazy ghost under its foreign key before
        // anything confirms the row exists, so a map hit is not evidence of a
        // row: a dangling, soft-deleted or out-of-tenant FK would otherwise
        // make find() return a hollow model, which breaks "a missing or
        // foreign id is a 404" and hands a policy a phantom subject.
        // isPersisted() is true only after markClean() -- hydrate from a real
        // row, or an insert -- so it separates both ghost cases from a read.
        // The query runner's resolveModels() guards the same way; every other
        // resolution path (Binder, ActionRunner, Validator) comes through
        // here, so the guard belongs here too.
        // A trashed row the map holds -- a #[BelongsTo] read it, or a
        // withTrashed() query did -- is no answer to a read that passes
        // trashed rows by; the database is asked instead.
        $meta = Metadata::for($class);
        if (!$this->isWidened() && ($existing = $this->identityMap->get($class, $id))) {
            /** @var Model $existing */
            if (self::holdsRow($existing) && $trashed->admits($meta->isTrashed($existing))) {
                return $existing;
            }
        }

        return $this->whereIn($class, $meta->idColumn, [$id], trashed: $trashed)[0] ?? null;
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @param string|null     $orderBy any column the model maps, hidden ones
     *        included: a caller passing request input allowlists it first
     *        (see identifier())
     * @param Trashed $trashed which of a #[SoftDeletes] model's rows: the live ones unless said
     * @return list<T>
     */
    public function all(string $class, ?string $orderBy = null, Trashed $trashed = Trashed::Without): array
    {
        $meta = Metadata::for($class);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->readScope($meta, $trashed);

        $columns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $meta->selectColumns()));
        $sql = "SELECT {$columns} FROM \"{$meta->table}\"";
        if ($scope !== '') {
            $sql .= " WHERE {$scope}";
        }
        if ($orderBy !== null) {
            $sql .= " ORDER BY \"{$orderBy}\"";
        }

        return $this->hydrateAll($class, $this->connection->select($sql, $scopeBindings));
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @param string          $column  any column the model maps, hidden ones
     *        included: filtering by request input is an oracle for a hidden
     *        column as much as sorting is, so a caller allowlists it first
     *        (see identifier())
     * @param list<mixed>     $values
     * @param string|null     $orderBy the same, for the sort
     * @param Trashed $trashed which of a #[SoftDeletes] model's rows: the live ones unless said
     * @return list<T>
     */
    public function whereIn(string $class, string $column, array $values, ?string $orderBy = null, Trashed $trashed = Trashed::Without): array
    {
        if ($values === []) {
            return [];
        }
        $meta = Metadata::for($class);
        $column = self::identifier($meta, $column);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->readScope($meta, $trashed);

        // find()'s guard, for every lookup by id: a value that is not a UUIDv7
        // names no row. Postgres refuses one for a uuid column with an error
        // quoting it, so Exists and Unique turned a malformed id into a 500,
        // and one bad id among a tick's batched model lookups failed every
        // query call in the tick. After the tenant check, which must fail
        // closed whatever the values. A #[BelongsTo]'s foreign key holds a
        // key too, and is a uuid column as much as the id is: Exists on one
        // was the same 500 (Query::where() guards both alike).
        if ($column === $meta->idColumn || self::isForeignKey($meta, $column)) {
            $values = array_values(array_filter(
                $values,
                static fn (mixed $value): bool => \is_string($value) && Uuid::isValid($value),
            ));
            if ($values === []) {
                return [];
            }
        }

        // maxValues() at a time, so no list outgrows what a driver
        // binds -- SQLite's 32,766 values, Postgres's and MySQL's 65,535 --
        // and each value once, or a value in two chunks came back twice. An
        // order is the database's, which only one statement keeps. Once by
        // its type and its exact value, as Query::whereIn() binds them:
        // PHP's loose == takes '1000' and '1e3' for one, and kept the first
        // alone, so a text column's '1e3' row was never read (ADR 0208).
        $unique = [];
        foreach ($values as $i => $value) {
            $unique[\is_scalar($value) ? get_debug_type($value) . "\0" . $value : "\1" . $i] = $value;
        }
        $values = array_values($unique);
        if ($orderBy !== null && \count($values) > $this->maxValues()) {
            throw new \LengthException(
                'whereIn() orders at most ' . $this->maxValues() . ' values, which one statement reads in the database\'s order: '
                . 'read more unordered, or page through them with query()->whereIn()->orderBy().',
            );
        }

        $rows = [];
        foreach (array_chunk($values, $this->maxValues()) as $chunk) {
            array_push($rows, ...$this->connection->select(
                $this->selectSql($meta, $column, \count($chunk), $orderBy, scope: $scope),
                [...$chunk, ...$scopeBindings],
            ));
        }

        return $this->hydrateAll($class, $rows);
    }

    /** Whether $column is the foreign key of one of the model's #[BelongsTo] relations. */
    private static function isForeignKey(ModelMeta $meta, string $column): bool
    {
        foreach ($meta->relations as $relation) {
            if ($relation->kind === RelationKind::BelongsTo && $relation->foreignKey === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * The common tail of hydrating a row: attach it, wire its relations and
     * snapshot it for change tracking.
     *
     * @param array<string, mixed> $row
     */
    private function settle(Model $model, ModelMeta $meta, array $row, ResultSet $resultSet): void
    {
        $model->attach($this, $resultSet);
        $this->installRelations($model, $meta, $row);
        $model->markClean($meta->writeValues($model));

        if ($this->reads !== null) {
            $this->reads[] = $model;
        }
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @param list<array<string, mixed>> $rows
     * @return list<T>
     *
     * @internal for the Orm's own relation loading and the batch
     *     scheduler's, inside this package
     */
    public function hydrateAll(string $class, array $rows): array
    {
        $meta = Metadata::for($class);
        $resultSet = new ResultSet();
        $models = [];

        // Widened past the tenant -- unscoped, or across a subtree -- the
        // map is bypassed in both directions: what it holds is the tenant's
        // alone, and what is read here may be another's.
        $unscoped = $this->isWidened();

        $reflection = new \ReflectionClass($class);

        foreach ($rows as $row) {
            $id = (string) ($row[$meta->idColumn] ?? '');
            $model = $unscoped ? null : $this->identityMap->get($class, $id);

            if ($model === null) {
                $model = $reflection->newInstanceWithoutConstructor();
                $meta->hydrate($model, $row);
                if (!$unscoped) {
                    $model = $this->identityMap->remember($class, $id, $model);
                }
                $this->settle($model, $meta, $row, $resultSet);
            } elseif ($reflection->isUninitializedLazyObject($model) || !$model->isPersisted()) {
                // A #[BelongsTo] put a ghost here from a foreign key: a
                // promise that a row exists, not the row. We are now holding
                // that row. Discarding it left the ghost to fetch the same
                // row again on first touch -- one extra statement for a row
                // already in hand, and a window where find() saw a model that
                // was not persisted.
                //
                // Also repairs a model that IS initialized but was never
                // marked clean -- which a ghost that resolved its own row used
                // to be -- so isPersisted() tells the truth either way.
                //
                // The object itself is kept, because other holders already
                // reference it and identity is the point of this map. A live
                // ghost is marked initialized FIRST: writing to one would run
                // its initializer, which is the fetch being avoided.
                //
                // Decoded before either: a row that does not decode -- a
                // value-object column that fails its cast -- throws with the
                // ghost still a ghost, not marked initialized and empty.
                $values = $meta->decode($row);
                if ($reflection->isUninitializedLazyObject($model)) {
                    $reflection->markLazyObjectAsInitialized($model);
                }

                $meta->fill($model, $values);
                $this->settle($model, $meta, $row, $resultSet);
            }

            $resultSet->add($model);
            $models[] = $model;
        }

        return $models;
    }

    /** @param array<string, mixed> $row */
    private function installRelations(Model $model, ModelMeta $meta, array $row): void
    {
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind->collects()) {
                // Under the scope the parent was read in, as a ghost is.
                $meta->set($model, $property, Collection::lazy($this->hasManyLoader(
                    $relation,
                    $model,
                    $this->isUnscoped() ? null : $this->captureScope(),
                    $this->scopeName(),
                )));
                continue;
            }

            $foreignKey = $row[$relation->foreignKey] ?? null;
            $ghost = $foreignKey === null ? null : $this->singleRelationGhost($relation, $model, (string) $foreignKey);
            $model->rememberForeignKey($property, $foreignKey, $ghost);
            if ($ghost === null) {
                continue;   // left uninitialized; a nullable relation reads as unset
            }

            $meta->set($model, $property, $ghost);
        }
    }

    /**
     * What loads a #[HasMany] collection: under the scope its parent was
     * read in, wherever it is first read, for the reason a ghost loads under
     * its own (singleRelationGhost()). First read inside acrossTenants() or
     * as another tenant, a collection loaded every row pointing at the
     * parent there -- another tenant's among them -- and kept them after the
     * block, in scoped code that would have been refused them; first read
     * as another tenant, it held that tenant's rows and none of its own.
     * A parent read inside acrossTenants() has no scope to keep ($scope is
     * null): its collections load under whatever scope is in force when
     * they are first read -- across tenants inside the block, the tenant's
     * own after it.
     *
     * @param (\Closure(\Closure(): mixed): mixed)|null $scope
     * @return \Closure(): list<Model>
     */
    private function hasManyLoader(RelationMeta $relation, Model $parent, ?\Closure $scope, string $madeIn): \Closure
    {
        return function () use ($relation, $parent, $scope, $madeIn): array {
            if ($scope === null || (!$this->isUnscoped() && $this->scopeName() === $madeIn)) {
                return $this->resolveHasMany($relation, $parent);
            }

            $items = [];
            $scope(function () use ($relation, $parent, &$items): void {
                $items = $this->resolveHasMany($relation, $parent);
            });

            return $items;
        };
    }

    /**
     * A lazy ghost for a single relation, as the spec specifies. Its initializer
     * deliberately never suspends -- see ADR 0002 -- so it resolves through
     * Level 1: one whereIn across the parent's whole result set.
     */
    private function singleRelationGhost(RelationMeta $relation, Model $parent, string $foreignKey): Model
    {
        $target = $relation->target;
        $targetMeta = Metadata::for($target);

        // A ghost made under a scope belongs to it, wherever it is first
        // touched. First touched inside acrossTenants() or as another tenant,
        // it read its row there -- another tenant's, if the key pointed
        // there -- and kept that row after the block ended, in scoped code
        // that would have been refused it. So it loads under the scope it was
        // made in. To read a relation across tenants, read its parent inside
        // the block: a ghost made there carries no scope.
        $scope = $this->isUnscoped() ? null : $this->captureScope();
        $madeIn = $this->scopeName();

        /** @var Model $ghost */
        $ghost = (new \ReflectionClass($target))->newLazyGhost(
            function (Model $object) use ($relation, $parent, $foreignKey, $targetMeta, $scope, $madeIn): void {
                if ($scope !== null && ($this->isUnscoped() || $this->scopeName() !== $madeIn)) {
                    $scope(function () use ($object, $relation, $parent, $foreignKey, $targetMeta): void {
                        $this->initializeGhost($object, $relation, $parent, $foreignKey, $targetMeta);
                    });

                    return;
                }

                $this->initializeGhost($object, $relation, $parent, $foreignKey, $targetMeta);
            },
        );

        // Registered before it initializes, so every parent pointing at this row
        // shares one instance and one initializer run. Not while unscoped, or
        // across a subtree: a ghost put in the map there would be served to a
        // later read scoped to the tenant alone.
        return $this->isWidened()
            ? $ghost
            : $this->identityMap->remember($target, $foreignKey, $ghost);
    }

    /**
     * The scope a read is in, as a string two reads in the same one share:
     * the tenant, and the tenants withinSubtree() or a page of
     * withinSubtreePages() widened it to -- by a digest of them, so two
     * pages of one walk are two scopes, and a relation read in one loads
     * under it when touched in the other (ADR 0196).
     */
    private function scopeName(): string
    {
        $subtree = $this->subtreeInForce();
        if ($subtree === null) {
            return $this->tenant();
        }
        // The same list is the same array while a scope lasts, which
        // compares at once: a digest of up to 10,000 names, once per scope.
        if ($subtree !== $this->scopeDigestOf) {
            $this->scopeDigestOf = $subtree;
            $this->scopeDigest = hash('xxh128', implode("\0", $subtree));
        }

        return $this->tenant() . "\0subtree\0" . $this->scopeDigest;
    }

    /**
     * Fills a #[BelongsTo] ghost from its row, under whatever scope the caller
     * has put in force.
     */
    private function initializeGhost(Model $object, RelationMeta $relation, Model $parent, string $foreignKey, ModelMeta $targetMeta): void
    {
        $row = $this->resolveBelongsToRow($relation, $parent, $foreignKey);
        if ($row === null) {
            // Throwing leaves the object UNINITIALIZED, so a later
            // touch tries again -- which matters when the first touch
            // happened under a scope that hid the row. Returning
            // instead marked it initialized and empty, permanently.
            // The miss is cached under that scope's key only, and an
            // unscoped load does not consult the cache, so a retry
            // as another tenant or inside acrossTenants() can succeed.
            throw DanglingRelation::for(
                $parent::class,
                $relation->property,
                $relation->target,
                $foreignKey,
            );
        }
        $targetMeta->hydrate($object, $row);

        // Through settle(), so a ghost that found its row ends up in
        // exactly the state a hydrated row does -- attached, relations
        // installed, and MARKED CLEAN. It used to skip markClean(), so
        // a model reached by touching a #[BelongsTo] stayed
        // isPersisted() === false for the rest of the request: find()'s
        // guard re-queried it on every lookup and still handed back the
        // same instance, and save() on it issued an INSERT for a row
        // that already existed.
        // The object joins the result set it is settled into, so a
        // relation read on it afterwards has a batch to widen to
        // rather than an empty set -- a set of itself alone, since it
        // was filled by itself, and a load for it is one parent's.
        $set = new ResultSet(alone: true);
        $this->settle($object, $targetMeta, $row, $set);
        $set->add($object);
    }

    // ------------------------------------------------------------- writing

    /** Refuses a write inside a loader or query body. */
    private function refuseWrite(string $what): void
    {
        if ($this->isReadOnly()) {
            throw ReadOnlyViolation::write($what, 'a loader or query body');
        }
    }

    /**
     * Refuses a write through the query builder inside a loader or query
     * body, as save() and delete() are refused there: before anything is
     * read, so a GET that would write reads nothing for it either.
     *
     * @internal for the query builder's writes, inside this package
     */
    public function refuseWriteOf(string $what): void
    {
        $this->refuseWrite($what);
    }

    /**
     * Whether the identity map holds a row of $class under $id: a model
     * read, not a ghost standing for a key.
     *
     * @param class-string<Model> $class
     *
     * @internal for the query builder's writes, inside this package
     */
    public function holdsModel(string $class, string $id): bool
    {
        $model = $this->identityMap->get($class, $id);

        return $model !== null && self::holdsRow($model);
    }

    /**
     * Brings what this Orm holds up to date with a row a statement wrote --
     * the query builder's update(), delete(), insert() or upsert() -- as
     * save() and delete() bring it up to date with theirs: the model the
     * identity map holds for the row, the relation-row cache, and every
     * loaded relation the row is in. All of it undone if the transaction
     * rolls back.
     *
     * A held model takes the written columns' values as $row has them --
     * read back from the database for a model held, so what it shows is
     * what was stored -- and its snapshot takes them too, so they read as
     * saved; a change it had to any other column is still pending. A
     * #[BelongsTo] whose key was written gets a ghost for the new key.
     *
     * @param array<string, mixed>|null $row the row's columns, at least those written; null once deleted
     * @param list<string> $columns the columns the statement wrote
     * @param bool $whole whether $row is the whole row as stored, read back after the write: the
     *        loaded relations are then told all of it, so a #[HasMany] can place a child it did not
     *        hold -- one restore() brought back (ADR 0191) -- while a held model still takes only
     *        the columns written
     *
     * @internal for the query builder's writes, inside this package
     */
    public function followStatementWrite(ModelMeta $meta, string $id, ?array $row, array $columns, bool $whole = false): void
    {
        $model = $this->identityMap->get($meta->class, $id);
        $held = $model instanceof Model && self::holdsRow($model) ? $model : null;

        if ($row === null) {
            $this->identityMap->forget($meta->class, $id);
            if ($held !== null) {
                $this->journalSnapshot($held);
                $held->markDeleted();
            }
            $this->followWrite($meta, $id, null);

            return;
        }

        $written = array_intersect_key($row, array_flip($columns));
        if ($held !== null) {
            $this->refreshHeld($held, $meta, $written);
        }
        $this->followWrite($meta, $id, $whole ? $row : $written);
    }

    /**
     * A held model given the columns a statement wrote, with what it was
     * before noted for a rollback to put back.
     *
     * @param array<string, mixed> $written column => value, as read
     */
    private function refreshHeld(Model $model, ModelMeta $meta, array $written): void
    {
        $values = $meta->decode($written);

        $relations = [];
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind === RelationKind::BelongsTo && \array_key_exists($relation->foreignKey, $written)) {
                $relations[$property] = $relation;
            }
        }

        // What it was, for a rollback: each property's value -- unset ones
        // stay unset -- its relations' keys, and its snapshot.
        $reflection = new \ReflectionClass($model);
        $before = [];
        foreach ([...array_keys($values), ...array_keys($relations)] as $property) {
            if ($reflection->getProperty($property)->isInitialized($model)) {
                $before[$property] = $meta->get($model, $property);
            }
        }
        $keys = [];
        foreach (array_keys($relations) as $property) {
            $keys[$property] = [$model->foreignKey($property), $model->installedRelation($property)];
        }
        $snapshot = $model->snapshot();
        $this->journal(static function () use ($model, $meta, $before, $keys, $snapshot): void {
            $meta->fill($model, $before);
            foreach ($keys as $property => [$key, $installed]) {
                $model->rememberForeignKey($property, $key, $installed);
            }
            $model->restoreSnapshot($snapshot);
        });

        $meta->fill($model, $values);
        foreach ($relations as $property => $relation) {
            $key = $written[$relation->foreignKey];
            if ($key === null) {
                $model->rememberForeignKey($property, null);
                $meta->set($model, $property, null);
                continue;
            }
            if ((string) $key === (string) $model->foreignKey($property)) {
                continue;
            }
            $ghost = $this->singleRelationGhost($relation, $model, (string) $key);
            $model->rememberForeignKey($property, (string) $key, $ghost);
            $meta->set($model, $property, $ghost);
        }

        // The written columns as the model writes them now, over what it
        // was read with: they read as saved, and nothing else changes.
        $model->markClean([...($snapshot ?? []), ...array_intersect_key($meta->writeValues($model), $written)]);
    }

    /**
     * Writes a model, inserting or updating as appropriate.
     *
     * "save() writes immediately, as in Laravel." Only the diff is written:
     * original column values are snapshotted on hydrate, so an update touches
     * the columns that changed and nothing else.
     *
     * The model's #[BeforeSave] methods run first, so what they change is in
     * the diff; one that throws stops the save with nothing written. They
     * run after the read-only check, so a save refused inside a loader or
     * query body runs none: a hook cannot do what the scope forbids. They
     * open no transaction of their own, so a hook's writes to other rows are
     * undone by a failed save only when the save runs inside transaction().
     *
     * A model with #[AfterSave] methods is saved in a transaction of its
     * own -- a savepoint inside one open already -- with its #[BeforeSave]
     * methods, the write and those hooks in it, run once the row is written:
     * a hook that throws takes the row back, and every write the hooks made
     * with it (ADR 0122). A save that writes nothing runs none.
     *
     * @template T of Model
     * @param T $model
     * @return T
     */
    public function save(Model $model): Model
    {
        $this->refuseWrite('save()');

        $meta = Metadata::for($model::class);
        if (!$meta->hasAfterSave() && $meta->audited === null) {
            $meta->beforeSave($model);
            $model->isPersisted() ? $this->update($model, $meta) : $this->insert($model, $meta);

            return $model;
        }
        $this->refuseUnrecorded($meta);

        // An audited model's change is recorded in the transaction it is
        // made in, so the two commit or roll back together (ADR 0156).
        $this->transaction(function () use ($model, $meta): void {
            $meta->beforeSave($model);
            $persisted = $model->isPersisted();
            $before = $model->snapshot();
            $pending = $persisted ? array_keys($model->changes()) : null;
            $tenant = $persisted ? $this->rowTenantFor($meta, $before, $meta->keyOf($model)) : [];
            if ($persisted ? $this->update($model, $meta) : $this->insert($model, $meta)) {
                $this->recordChange(
                    $meta,
                    $persisted ? ModelChangeKind::Updated : ModelChangeKind::Created,
                    $meta->keyOf($model),
                    $before === null ? null : [...$before, ...$tenant],
                    [...($model->snapshot() ?? []), ...$tenant],
                    $pending,
                );
                $meta->afterSave($model);
            }
        });

        return $model;
    }

    /**
     * The time a write is made at, as the ORM stamps it: a new row's
     * createdAt, and the updatedAt of a row inserted or updated.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->clock === null
            ? \DateTimeImmutable::createFromInterface(CarbonImmutable::now('UTC'))
            : ($this->clock)();
    }

    /** The form a moment is written in on this Orm's database (TimestampStorage). */
    public function timestampStorage(): TimestampStorage
    {
        return $this->timestampStorage;
    }

    /** @return true a row was written, always */
    private function insert(Model $model, ModelMeta $meta): bool
    {
        // UUIDv7 is minted here rather than by the database, so the model has
        // its key before the write and the identity map can hold it.
        if (!$this->isSet($model, $meta, $meta->idProperty)) {
            $meta->set($model, $meta->idProperty, Uuid::v7());
        } elseif (!Uuid::isValid((string) $meta->get($model, $meta->idProperty))) {
            // A key find() would never look up. find() answers a non-UUID
            // key without asking the database, so a row stored under one
            // could be written and never read back.
            throw new MappingException("{$meta->class}: a preset key must be a UUID.");
        }

        // "Asymmetric visibility keeps IDs and timestamps read-only outside
        // the ORM": a createdAt typed as a moment, and `private(set)` as the
        // spec writes it, is the ORM's to fill, as the key is. One the model
        // set itself -- a backfill, an import keeping its dates -- is kept.
        // An updatedAt likewise, at the same moment, so a new row reads as
        // changed when it was made.
        $now = $this->now();
        foreach ([$meta->createdAtProperty, $meta->updatedAtProperty] as $stamped) {
            if ($stamped !== null && !$this->isSet($model, $meta, $stamped)) {
                $meta->stamp($model, $stamped, $now);
            }
        }

        // A new row is live: trashed only by delete(), which the hooks and
        // the audit log hear of (ADR 0191).
        $deletedAt = $meta->deletedAtProperty;
        if ($deletedAt !== null) {
            if (!$this->isSet($model, $meta, $deletedAt)) {
                $meta->set($model, $deletedAt, null);
            } elseif ($meta->get($model, $deletedAt) !== null) {
                throw self::trashedByHand($meta);
            }
        }

        // Not attached yet, so told the form a moment is written in here.
        $values = $meta->writeValues($model, $this->timestampStorage);

        if ($meta->tenantColumn !== null) {
            if (!$this->tenancy) {
                throw NoTenantContext::noTenancy($meta->class);
            }
            $tenant = $this->identityMap->tenant();
            if ($tenant === '') {
                throw new NoTenantContext($meta->class);
            }
            $this->refuseNewRowUnread($meta->class);
            // Stamped, not taken from the model: a caller must not be able to
            // write a row into another tenant.
            $values[$meta->tenantColumn] = $tenant;
        }

        $columns = array_keys($values);
        $this->connection->execute(
            sprintf(
                'INSERT INTO "%s" (%s) VALUES (%s)',
                $meta->table,
                implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns)),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($values),
        );

        // A model that maps the tenant column holds the tenant its row was
        // stamped with, as it does its minted key. Left as it was -- unset,
        // or naming another tenant -- reading it threw, and the next save
        // read the stamp as a move and refused it.
        $tenantProperty = $meta->tenantColumn === null ? false : array_search($meta->tenantColumn, $meta->columns, true);
        if (\is_string($tenantProperty)) {
            $meta->set($model, $tenantProperty, $values[$meta->tenantColumn]);
        }

        $this->journalSnapshot($model);
        $model->markClean($values);
        $this->rememberWrittenKeys($model, $meta, $values);
        $model->attach($this, new ResultSet());
        $this->identityMap->remember($meta->class, $meta->keyOf($model), $model);
        $this->followWrite($meta, $meta->keyOf($model), $values);

        return true;
    }

    /** @return bool whether a row was written: false where nothing had changed */
    private function update(Model $model, ModelMeta $meta): bool
    {
        $changes = $model->changes();
        unset($changes[$meta->idColumn]);

        // The tenant is stamped on insert and never updated. Left in the
        // diff, a model that maps the column would move its own row into
        // another tenant, with the WHERE predicate still satisfied by the
        // tenant it is leaving; dropped from it without a word, the model
        // would go on naming a tenant its row is not in. Refused, as a
        // builder's update of the column is.
        if ($meta->tenantColumn !== null && \array_key_exists($meta->tenantColumn, $changes)) {
            throw ForeignTenantRow::moved($meta->class);
        }

        // A #[SoftDeletes] moment is delete()'s and restore()'s to write.
        if ($meta->deletedAtColumn !== null && \array_key_exists($meta->deletedAtColumn, $changes)) {
            throw self::trashedByHand($meta);
        }

        if ($changes === []) {
            return false;   // nothing to write
        }

        // Every update moves updatedAt, unless this save set it itself -- a
        // change to it is in the diff -- as insert() keeps one the model set.
        // What it held is put back if the write is refused or rolled back,
        // so a save tried again stamps it again rather than keeping this
        // stamp as if the model had set it.
        $updated = $meta->updatedAtProperty;
        $restore = null;
        if ($updated !== null && !\array_key_exists($meta->columns[$updated], $changes)) {
            $previous = $this->isSet($model, $meta, $updated) ? [$updated => $meta->get($model, $updated)] : [];
            $meta->stamp($model, $updated, $this->now());
            $changes[$meta->columns[$updated]] = $meta->writeValues($model)[$meta->columns[$updated]];
            $restore = static function () use ($model, $meta, $previous): void {
                $meta->fill($model, $previous);
            };
        }

        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf(
            'UPDATE "%s" SET %s WHERE "%s" = ?',
            $meta->table,
            implode(', ', array_map(
                static fn (string $c): string => '"' . $c . '" = ?',
                array_keys($changes),
            )),
            $meta->idColumn,
        );

        // The tenant predicate is on the write too: an id from another tenant
        // must not be updatable even if one were somehow held.
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        try {
            $bindings = [...array_values($changes), $meta->keyOf($model), ...$scopeBindings];
            $affected = $this->connection->execute($sql, $bindings);

            // MySQL counts the rows an UPDATE changed, not the rows it
            // matched: one whose values the row held already -- the same
            // change saved by two requests -- counted none (ADR 0172).
            if ($affected === 0 && $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql') {
                $affected = $this->updatedOnMysql($meta, $model, $sql, $bindings, $scope, $scopeBindings);
            }

            // Nothing matched: the row is gone, or the tenant predicate excluded
            // it. Treated as success, the change was lost and the model marked
            // clean, so a retry sent nothing either. Thrown before markClean() so
            // the change stays pending. (SQLite and Postgres count the rows
            // matched; on MySQL updatedOnMysql() has asked.)
            if ($affected === 0) {
                throw MissingRow::onUpdate($meta->class, $meta->keyOf($model));
            }
        } catch (\Throwable $e) {
            if ($restore !== null) {
                $restore();
            }
            throw $e;
        }

        if ($restore !== null) {
            $this->journal($restore);
        }
        $this->noteRowTenants($meta, [$meta->keyOf($model)]);
        $written = $meta->writeValues($model);
        $this->journalSnapshot($model);
        $model->markClean($written);
        $this->rememberWrittenKeys($model, $meta, $written);
        $this->followWrite($meta, $meta->keyOf($model), $written);

        return true;
    }

    /**
     * The #[BelongsTo] keys a save wrote, as the model's own record of them
     * (Model::foreignKey()), as hydration records the ones it reads: a row
     * inserted or moved this request said '' for its key, or the old one,
     * and a pivot's links were matched by it (ADR 0191). The ghost
     * hydration installed is kept for a key that did not change. Put back
     * if the transaction rolls back.
     *
     * @param array<string, mixed> $values column => value, as written
     */
    private function rememberWrittenKeys(Model $model, ModelMeta $meta, array $values): void
    {
        $before = [];
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind !== RelationKind::BelongsTo || !\array_key_exists($relation->foreignKey, $values)) {
                continue;
            }
            $was = $model->foreignKey($property);
            $installed = $model->installedRelation($property);
            $before[$property] = [$was, $installed];
            $key = $values[$relation->foreignKey];
            $model->rememberForeignKey($property, $key, (string) $was === (string) $key ? $installed : null);
        }
        if ($before !== []) {
            $this->journal(static function () use ($model, $before): void {
                foreach ($before as $property => [$key, $installed]) {
                    $model->rememberForeignKey($property, $key, $installed);
                }
            });
        }
    }

    /**
     * On MySQL, after an update() that counted no row: 1 when the row is
     * there, under the same tenant predicate, and holds the change; 0 when
     * it is not.
     *
     * Asked with the row locked (FOR UPDATE), in a transaction of its own --
     * a savepoint inside one open already -- and the UPDATE sent again under
     * the lock: a model deleted and saved again inserts its row under the
     * same key, and a row put back between the UPDATE that found none and
     * a read that found it would otherwise read as written without holding
     * the change. A locking read sees the row as it stands, not as a
     * transaction already open first read it.
     *
     * @param list<mixed> $bindings the UPDATE's
     * @param list<mixed> $scopeBindings
     */
    private function updatedOnMysql(ModelMeta $meta, Model $model, string $sql, array $bindings, string $scope, array $scopeBindings): int
    {
        return $this->connection->transaction(function () use ($meta, $model, $sql, $bindings, $scope, $scopeBindings): int {
            $there = $this->connection->select(
                sprintf('SELECT 1 FROM "%s" WHERE "%s" = ?', $meta->table, $meta->idColumn) . ($scope !== '' ? " AND {$scope}" : '') . ' FOR UPDATE',
                [$meta->keyOf($model), ...$scopeBindings],
            );
            if ($there === []) {
                return 0;
            }
            $this->connection->execute($sql, $bindings);

            return 1;
        });
    }

    /**
     * Deletes a row, scoped to the tenant like every other write.
     *
     * A model with #[BeforeDelete] or #[AfterDelete] methods is deleted in a
     * transaction of its own -- a savepoint inside one open already -- with
     * the first run before the DELETE and the second after it: a hook that
     * throws stops the delete, or puts the row back, and takes back what
     * the hooks wrote with it (ADR 0122).
     *
     * A model is deleted only where it has a row: one never saved, or
     * deleted already, is refused before any hook runs, and a DELETE that
     * removed nothing -- the row deleted since it was read, or out of the
     * tenant's reach, as a child's read inside withinSubtree() is after it
     * ends -- throws MissingRow, worded alike for each so the difference
     * is no oracle (ADR 0208). With hooks, inside the transaction, taking
     * back what #[BeforeDelete] wrote, and running no #[AfterDelete]: a
     * hook that hears of a delete hears of one that happened.
     *
     * A #[SoftDeletes] model's row is trashed rather than removed: its
     * deleted_at stamped with now(), the model still persisted and
     * isTrashed(), its hooks run around the UPDATE as around a DELETE,
     * and an audited model's change recorded as `trashed` (ADR 0191).
     * One trashed already is no row to trash: refused with MissingRow, as
     * a row gone is. forceDelete() removes
     * the row.
     */
    public function delete(Model $model): void
    {
        $this->refuseWrite('delete()');

        $meta = Metadata::for($model::class);
        if ($meta->softDeletes()) {
            $this->trash($model, $meta);

            return;
        }
        $this->remove($model, $meta);
    }

    /**
     * Removes the row, a #[SoftDeletes] model's too, trashed or not: what
     * delete() does for any other model, its hooks and its audit entry --
     * `deleted` -- with it (ADR 0191).
     */
    public function forceDelete(Model $model): void
    {
        $this->refuseWrite('forceDelete()');

        $this->remove($model, Metadata::for($model::class));
    }

    /**
     * Brings a trashed row of a #[SoftDeletes] model back: its deleted_at
     * cleared, and every read reading it again. An audited model's change
     * is recorded as `restored`, in the transaction of the write (ADR
     * 0191). No hook runs: a restore is neither a save nor a delete.
     *
     * A model whose row is not trashed, as it was last read or written,
     * is left as it is. One the UPDATE does not reach -- the row gone,
     * restored since, or another tenant's -- throws MissingRow, worded
     * alike for each, so the difference is no oracle.
     */
    public function restore(Model $model): void
    {
        $this->refuseWrite('restore()');

        $meta = Metadata::for($model::class);
        if (!$meta->softDeletes()) {
            throw new \LogicException("{$meta->class} is not #[SoftDeletes]: its rows are deleted, never trashed, so there is none to restore.");
        }
        if (!$model->isPersisted()) {
            throw MissingRow::notPersisted($meta->class, 'Restoring');
        }
        if (!$meta->isTrashed($model)) {
            return;
        }
        if ($meta->audited === null) {
            if ($this->restoreRow($model, $meta) === 0) {
                throw MissingRow::onRestore($meta->class, $meta->keyOf($model));
            }

            return;
        }
        $this->refuseUnrecorded($meta);

        $this->transaction(function () use ($model, $meta): void {
            $tenant = $this->rowTenantFor($meta, $model->snapshot(), $meta->keyOf($model));
            if ($this->restoreRow($model, $meta) === 0) {
                throw MissingRow::onRestore($meta->class, $meta->keyOf($model));
            }
            $this->recordChange($meta, ModelChangeKind::Restored, $meta->keyOf($model), null, [...($model->snapshot() ?? []), ...$tenant]);
        });
    }

    /** What insert() and update() refuse: a #[SoftDeletes] moment written by hand. */
    private static function trashedByHand(ModelMeta $meta): \LogicException
    {
        return new \LogicException(
            "{$meta->class}::\${$meta->deletedAtProperty} is #[SoftDeletes]'s, and is written by delete() and restore() alone, "
            . 'where the delete hooks run and the audit log hears of it: call those rather than setting it.',
        );
    }

    /**
     * delete() of a #[SoftDeletes] model: the trashing UPDATE, in a
     * transaction with the delete hooks and the audit entry where the
     * model has either.
     */
    private function trash(Model $model, ModelMeta $meta): void
    {
        if (!$model->isPersisted()) {
            throw MissingRow::notPersisted($meta->class);
        }
        if (!$meta->hasDeleteHooks() && $meta->audited === null) {
            if ($this->trashRow($model, $meta) === 0) {
                throw MissingRow::onDelete($meta->class, $meta->keyOf($model));
            }

            return;
        }
        $this->refuseUnrecorded($meta);

        $this->transaction(function () use ($model, $meta): void {
            $meta->beforeDelete($model);
            $before = $model->snapshot();
            $before = $before === null ? null : [...$before, ...$this->rowTenantFor($meta, $before, $meta->keyOf($model))];
            if ($this->trashRow($model, $meta) === 0) {
                throw MissingRow::onDelete($meta->class, $meta->keyOf($model));
            }
            $this->recordChange($meta, ModelChangeKind::Trashed, $meta->keyOf($model), $before, null);
            $meta->afterDelete($model);
        });
    }

    /**
     * Stamps the row's deleted_at, where it is live and the tenant's: the
     * model takes the moment, its snapshot the stored form, and every
     * collection it was in lets it go. All of it undone if the
     * transaction rolls back.
     *
     * @return int the rows trashed: 0 where the row is gone, trashed already, or out of reach
     */
    private function trashRow(Model $model, ModelMeta $meta): int
    {
        $property = (string) $meta->deletedAtProperty;
        $column = (string) $meta->deletedAtColumn;
        $previous = $this->isSet($model, $meta, $property) ? [$property => $meta->get($model, $property)] : [];
        $meta->stamp($model, $property, $this->now());
        $stored = $meta->columnValues($model)[$column];

        $written = $this->writeTrashed($model, $meta, $stored);
        if ($written === 0) {
            $meta->fill($model, $previous);
        } else {
            $this->journal(static fn () => $meta->fill($model, $previous));
        }

        return $written;
    }

    /** Clears the row's deleted_at, where it is trashed and the tenant's: trashRow()'s converse. */
    private function restoreRow(Model $model, ModelMeta $meta): int
    {
        $property = (string) $meta->deletedAtProperty;
        $previous = [$property => $meta->get($model, $property)];

        $written = $this->writeTrashed($model, $meta, null);
        if ($written > 0) {
            $meta->set($model, $property, null);
            $this->journal(static fn () => $meta->fill($model, $previous));
        }

        return $written;
    }

    /**
     * The one UPDATE of a row's deleted_at: to $stored where the row is
     * live, to NULL where it is trashed, under the tenant predicate as
     * every write is. On a write that reached the row, the snapshot takes
     * the value and the relations follow.
     */
    private function writeTrashed(Model $model, ModelMeta $meta, ?string $stored): int
    {
        $column = (string) $meta->deletedAtColumn;
        $id = $meta->keyOf($model);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf(
            'UPDATE "%s" SET "%s" = ? WHERE "%s" = ? AND "%s" %s',
            $meta->table,
            $column,
            $meta->idColumn,
            $column,
            $stored === null ? 'IS NOT NULL' : 'IS NULL',
        );
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        $this->noteRowTenants($meta, [$id]);
        // Changed from NULL or to it, so MySQL's count of rows changed is
        // the count of rows matched (ADR 0172).
        $written = $this->connection->execute($sql, [$stored, $id, ...$scopeBindings]);
        if ($written === 0) {
            return 0;
        }

        $this->journalSnapshot($model);
        $model->markClean([...($model->snapshot() ?? []), $column => $stored]);
        $this->followWrite($meta, $id, $model->snapshot());

        return $written;
    }

    /** delete() of a model that is not #[SoftDeletes], and forceDelete() of any. */
    private function remove(Model $model, ModelMeta $meta): void
    {
        if (!$model->isPersisted()) {
            throw MissingRow::notPersisted($meta->class);
        }
        // A DELETE that removed nothing -- the row gone already, or out of
        // the tenant's reach -- throws, as an update that reached none
        // does: told it succeeded, a caller went on as if the row were
        // gone at its word (ADR 0208).
        if (!$meta->hasDeleteHooks() && $meta->audited === null) {
            if ($this->deleteRow($model, $meta) === 0) {
                throw MissingRow::onDelete($meta->class, $meta->keyOf($model));
            }

            return;
        }
        $this->refuseUnrecorded($meta);

        $this->transaction(function () use ($model, $meta): void {
            $meta->beforeDelete($model);
            $before = $model->snapshot();
            // Read while the row is there to read.
            $before = $before === null ? null : [...$before, ...$this->rowTenantFor($meta, $before, $meta->keyOf($model))];
            if ($this->deleteRow($model, $meta) === 0) {
                throw MissingRow::onDelete($meta->class, $meta->keyOf($model));
            }
            $this->recordChange($meta, ModelChangeKind::Deleted, $meta->keyOf($model), $before, null);
            $meta->afterDelete($model);
        });
    }

    /**
     * The model is left as it was where the DELETE removed nothing, for the
     * caller to refuse.
     *
     * @return int the rows the DELETE removed
     */
    private function deleteRow(Model $model, ModelMeta $meta): int
    {
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf('DELETE FROM "%s" WHERE "%s" = ?', $meta->table, $meta->idColumn);
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        // Read while the row is there to read.
        $this->noteRowTenants($meta, [$meta->keyOf($model)]);

        $deleted = $this->connection->execute($sql, [$meta->keyOf($model), ...$scopeBindings]);
        if ($deleted === 0) {
            return 0;
        }
        $this->identityMap->forget($meta->class, $meta->keyOf($model));

        // Left marked persisted, a later save() sent an UPDATE for a row that
        // no longer exists. It now inserts the row again, as for a new model.
        $this->journalSnapshot($model);
        $model->markDeleted();
        $this->followWrite($meta, $meta->keyOf($model), null);

        return $deleted;
    }

    /**
     * Wraps several writes. "Db::transaction(fn () => ...) wraps multiple
     * writes."
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        // A transaction opened inside a loader or query body is a write by
        // intent even if nothing in it writes through save().
        $this->refuseWrite('transaction()');

        $frames = $this->frames();
        $frames->journal[] = [];
        $frames->afterCommit[] = [];

        try {
            $result = $this->connection->transaction($work);
        } catch (\Throwable $e) {
            // Nothing it was waiting to announce happened.
            array_pop($frames->afterCommit);

            // The rows written inside the transaction are gone, but the models
            // still carry the snapshots those writes took: an undone insert
            // read as persisted and an undone update as clean, so retrying the
            // same save() wrote nothing. Loaded relations were refilled for
            // writes that no longer exist, too. Undone newest first, so a
            // model saved twice ends with the snapshot it had before either.
            foreach (array_reverse(self::popFrame($frames->journal)) as $undo) {
                $undo();
            }

            // And the identity map still serves those models. A later read
            // would be handed one of them rather than the database's state.
            $this->identityMap->flush();
            $this->relationRows = [];
            $this->writeGeneration++;

            throw $e;
        }

        // A nested call that returned is undone if the outer one rolls back.
        // A frame flush() dropped -- its fiber resumed after the request
        // ended -- has nothing left to fold.
        $frame = self::popFrame($frames->journal);
        if ($frames->journal !== []) {
            array_push($frames->journal[array_key_last($frames->journal)], ...$frame);
        }

        // And what waited on it waits on the outer one, which may still roll
        // back. Only the outermost commit is a commit anyone else can read.
        $waiting = self::popFrame($frames->afterCommit);
        if ($frames->afterCommit !== []) {
            array_push($frames->afterCommit[array_key_last($frames->afterCommit)], ...$waiting);
        } else {
            self::runAll($waiting);
        }

        return $result;
    }

    /**
     * Runs $callback once the writes made so far are committed: at once with
     * no transaction() open, after the outermost one commits otherwise, and
     * never if it rolls back.
     *
     * For what tells someone else about a write -- a live invalidation, a
     * job, a mail. Told before the commit, they ask for the data and read it
     * as it was, or are told of a write that is then rolled back; either way
     * what they hold is wrong and nothing corrects it.
     *
     * Only transactions opened here are seen. One begun on the connection
     * around this class commits when its caller says, which nothing here
     * can know.
     *
     * A callback should not throw: one that does after a commit makes
     * transaction() throw for writes that are in, and a caller that retries
     * on failure writes them twice. Every callback still runs, and the first
     * failure is rethrown once they have.
     *
     * @param \Closure(): void $callback
     */
    public function afterCommit(\Closure $callback): void
    {
        $frames = $this->frames();
        if ($frames->afterCommit === []) {
            $callback();

            return;
        }

        $frames->afterCommit[array_key_last($frames->afterCommit)][] = $callback;
    }

    /**
     * The innermost frame, or none: flush() empties the stacks under a
     * transaction left open in an abandoned fiber, which may still return.
     *
     * @template T
     * @param list<list<T>> $stack
     * @param-out list<list<T>> $stack
     * @return list<T>
     */
    private static function popFrame(array &$stack): array
    {
        return array_pop($stack) ?? [];
    }

    /** @param list<\Closure(): void> $callbacks */
    private static function runAll(array $callbacks): void
    {
        $failure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Notes a model's snapshot before a write replaces it, for a rollback to put back. */
    private function journalSnapshot(Model $model): void
    {
        $snapshot = $model->snapshot();
        $this->journal(static fn () => $model->restoreSnapshot($snapshot));
    }

    /** @param \Closure(): void $undo run if the open transaction rolls back */
    private function journal(\Closure $undo): void
    {
        $frames = $this->frames();
        if ($frames->journal !== []) {
            $frames->journal[array_key_last($frames->journal)][] = $undo;
        }
    }

    /**
     * The calling fiber's frames on a ConcurrentConnection, whose
     * transactions are each fiber's own; the one set otherwise, since
     * PdoConnection's one transaction is every fiber's.
     */
    private function frames(): TransactionFrames
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || !$this->connection instanceof ConcurrentConnection) {
            return $this->mainFrames;
        }

        return $this->framesByFiber[$fiber] ??= new TransactionFrames();
    }

    // ------------------------------------------------------- after a write

    /**
     * Brings every relation the written row belongs to up to date.
     *
     * "The identity map keeps loaded relations consistent after a write, with
     * no reload." It did not: a child moved to another parent stayed in the
     * old parent's loaded relation and never reached the new one's, and a
     * deleted child stayed listed. The relation-row cache those relations
     * hydrate from was as stale, so a parent read later in the request -- the
     * Revalidator's re-render after an action -- was served the rows as they
     * were before the write.
     *
     * Patched rather than dropped, because the spec promises no reload and
     * the row as written is already in hand.
     *
     * @param array<string, mixed>|null $row the row as written, null once deleted
     */
    private function followWrite(ModelMeta $meta, string $id, ?array $row): void
    {
        $this->writeGeneration++;
        foreach ($this->writeObservers as [, $observer]) {
            $observer($meta->class, $id, $row === null);
        }

        // A trashed child leaves every collection it was in, as a deleted
        // one does; its parent's #[BelongsTo] row is kept, trashed.
        $gone = $row === null || ($meta->deletedAtColumn !== null && ($row[$meta->deletedAtColumn] ?? null) !== null);

        foreach (Metadata::relationsTo($meta->class) as $relation) {
            match ($relation->kind) {
                RelationKind::HasMany => $this->followHasMany($relation, $meta, $id, $gone ? null : $row),
                RelationKind::BelongsToMany => $this->forgetLinks($relation),
                RelationKind::BelongsTo => $this->followBelongsTo($relation, $id, $row),
            };
        }
        // A pivot row written links two rows, or no longer does (ADR 0192).
        foreach (Metadata::relationsThrough($meta->class) as $relation) {
            $this->forgetLinks($relation);
        }
    }

    /**
     * A single relation's cached row for the written model.
     *
     * Hydration reads it whenever a ghost for the row is made afresh -- as
     * after a delete, which drops the model from the map -- so a deleted
     * row was handed back as if it were still there.
     *
     * @param array<string, mixed>|null $row
     */
    private function followBelongsTo(RelationMeta $relation, string $id, ?array $row): void
    {
        $key = $this->relationCacheKey($relation);
        if (!$this->hasRows($key, $id)) {
            return;
        }

        if ($row === null) {
            // A miss, which is what a read would now find.
            $this->relationRows[$key][$id] = null;
        } elseif ($this->isWidened()) {
            // Possibly another tenant's row, so not cached under this one's
            // key: dropped, and read again under the reader's scope.
            unset($this->relationRows[$key][$id]);
        } else {
            $cached = $this->relationRows[$key][$id];
            $this->relationRows[$key][$id] = [...(\is_array($cached) ? $cached : []), ...$row];
        }
    }

    /**
     * A collection relation the written row is a child in: out of the group
     * that held it, into the one its foreign key names now -- the same group
     * when the key did not change -- in the relation's order, and every
     * loaded collection for those parents refilled from the result.
     *
     * @param array<string, mixed>|null $row
     */
    private function followHasMany(RelationMeta $relation, ModelMeta $meta, string $id, ?array $row): void
    {
        $key = $this->relationCacheKey($relation);
        $column = $relation->foreignKey;

        // Found by id rather than by the old foreign key, which a child that
        // does not map the key never had.
        $previous = null;
        $from = null;
        $at = null;
        foreach ($this->relationRows[$key] ?? [] as $parentId => $rows) {
            /** @var list<array<string, mixed>> $rows */
            foreach ($rows as $i => $cached) {
                if ((string) ($cached[$meta->idColumn] ?? '') === $id) {
                    [$previous, $from, $at] = [$cached, (string) $parentId, $i];
                    break 2;
                }
            }
        }

        // A child that does not map the key keeps the parent it had.
        $to = null;
        if ($row !== null) {
            $parent = \array_key_exists($column, $row) ? $row[$column] : ($previous[$column] ?? null);
            $to = $parent === null ? null : (string) $parent;
        }

        if ($from !== null && $at !== null) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->relationRows[$key][$from];
            array_splice($rows, $at, 1);
            $this->relationRows[$key][$from] = $rows;
        }

        $written = $row === null ? [] : [...($previous ?? []), ...$row, $column => $to];

        if ($to !== null && $this->hasRows($key, $to)) {
            if ($this->isWidened() || array_diff($meta->selectColumns(), array_keys($written)) !== []) {
                // Possibly another tenant's row: a scoped parent must not be
                // handed it. Or a row written through a query that was never
                // read, of which only the columns written are known -- no row
                // to make a model of. Either way dropped, so the next read
                // loads it whole, under its scope.
                unset($this->relationRows[$key][$to]);
            } else {
                /** @var list<array<string, mixed>> $rows */
                $rows = $this->relationRows[$key][$to];

                // Back where it was if it stayed, so a relation with no order
                // keeps the order the database gave it -- and one whose order
                // value the write left alone keeps its place in that order.
                $stayed = $to === $from && $at !== null;
                if ($stayed) {
                    array_splice($rows, $at, 0, [$written]);
                } else {
                    $rows[] = $written;
                }

                $orderBy = $relation->orderBy;
                if ($orderBy !== null && !($stayed && ($previous[$orderBy] ?? null) === ($written[$orderBy] ?? null))) {
                    $rows = $this->sortedAsRead($rows, $orderBy);
                }

                if ($rows === null) {
                    unset($this->relationRows[$key][$to]);
                } else {
                    $this->relationRows[$key][$to] = $rows;
                }
            }
        }

        foreach (array_unique(array_filter([$from, $to], static fn (?string $p): bool => $p !== null)) as $parentId) {
            $this->refillLoaded($relation, $key, $parentId);
        }
    }

    /**
     * $rows in the order the database reads `ORDER BY $orderBy` in, or null
     * when that order cannot be told here and the rows are to be read again.
     *
     * PHP's <=> is not that order: it compares numeric strings as numbers,
     * '9' before '10', where the database puts '10' first. Numbers compare
     * alike everywhere, with NULL first ascending on SQLite and MySQL and
     * last on Postgres. Text SQLite orders by its bytes, as strcmp() does,
     * under the BINARY collation its columns have unless declared
     * otherwise. Postgres and MySQL order it by a collation -- the
     * database's, or the column's -- that no comparison here reproduces,
     * so a re-sort by text there is not guessed at.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>|null
     */
    private function sortedAsRead(array $rows, string $orderBy): ?array
    {
        $driver = $this->connection instanceof NamesDriver ? $this->connection->driver() : 'sqlite';
        foreach ($rows as $row) {
            $value = $row[$orderBy] ?? null;
            if (!($value === null || \is_int($value) || \is_float($value) || \is_bool($value) || (\is_string($value) && $driver === 'sqlite'))) {
                return null;
            }
        }

        // SQLite reads NULL, then numbers, then text; the others hold no text here.
        $rank = static fn (mixed $value): int => match (true) {
            $value === null => $driver === 'pgsql' ? 2 : 0,
            \is_string($value) => 3,
            default => 1,
        };
        usort($rows, static function (array $a, array $b) use ($orderBy, $rank): int {
            $x = $a[$orderBy] ?? null;
            $y = $b[$orderBy] ?? null;
            $byRank = $rank($x) <=> $rank($y);
            if ($byRank !== 0) {
                return $byRank;
            }

            return \is_string($x) && \is_string($y) ? strcmp($x, $y) : $x <=> $y;
        });

        return $rows;
    }

    /** Refills one parent's loaded collection from the relation-row cache. */
    private function refillLoaded(RelationMeta $relation, string $key, string $parentId): void
    {
        $parent = $this->identityMap->get($relation->owner, $parentId);

        // Not a ghost, whose relations are not installed yet, and read
        // without touching it: reading a ghost's property would load it.
        if ($parent === null || !self::holdsRow($parent)
            || !(new \ReflectionProperty($relation->owner, $relation->property))->isInitialized($parent)) {
            return;
        }

        $collection = Metadata::for($relation->owner)->get($parent, $relation->property);
        if (!$collection instanceof Collection || !$collection->isLoaded()) {
            return;   // a relation not read yet reads the patched cache when it is
        }

        $before = $collection->all();
        $this->journal(static fn () => $collection->replaceItems($before));

        if (!$this->isWidened() && $this->hasRows($key, $parentId)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->relationRows[$key][$parentId];
            $collection->replaceItems($this->hydrateAll($relation->target, $rows));

            return;
        }

        // Nothing cached to refill it from, or an unscoped write, whose
        // hydration would bypass the map: loaded again when next read --
        // under the parent's scope, the tenant's alone, since the map holds
        // nothing read wider.
        $tenant = $this->tenant();
        $collection->unload($this->hasManyLoader(
            $relation,
            $parent,
            fn (\Closure $work): mixed => $this->actAs($tenant, $work),
            $tenant,
        ));
    }

    private function isSet(Model $model, ModelMeta $meta, string $property): bool
    {
        return (new \ReflectionProperty($meta->class, $property))->isInitialized($model);
    }

    // ------------------------------------------------------------- relations

    /**
     * The parents a relation load should cover: the whole result set this
     * model came back in, or just this model.
     *
     * The choice used to be `?? [$parent]`, which never fired -- an EMPTY
     * result set is not null, so zero parents were handed to the loader, it
     * returned early, cached nothing, and the read fell through to an empty
     * collection. Silently, with no fibers and no tenancy involved: a model
     * reached through a #[BelongsTo] always has an empty result set, so
     * touching a relation and then reading a #[HasMany] on what came back
     * answered [].
     *
     * Just this model is a load for one parent alone, outside any batch --
     * see batchOf(), which says which a load was for the record a test reads.
     *
     * @return list<Model>
     */
    private function batchWith(Model $parent): array
    {
        $set = $parent->resultSet()?->all() ?? [];

        return $set === [] ? [$parent] : $set;
    }

    /**
     * @return list<Model>
     *
     * @internal for the Orm's own relation loading and the batch
     *     scheduler's, inside this package
     */
    public function resolveHasMany(RelationMeta $relation, Model $parent): array
    {
        $key = $this->relationCacheKey($relation);
        $groupKey = $parent->primaryKey();

        // An unscoped read never touches the cache, in either direction, so it
        // takes its answer straight from the load; nor does one across a
        // subtree, for the same reason. The load is kept on the result set
        // for its siblings, under the widened scope's own name.
        if ($this->isWidened()) {
            $grouped = $this->widenedRows($relation, $parent, $key);

            return $this->hydrateAll($relation->target, $grouped[$groupKey] ?? []);
        }

        if (!$this->hasRows($key, $groupKey)) {
            // Inside a render, hand the load to the scheduler so it joins every
            // other relation wanted in this tick. Outside one, fall back to
            // Level 1 across the result set.
            if ($this->scheduler !== null && \Fiber::getCurrent() !== null) {
                // With the rest of the result set, as Level 1 widens to it.
                // Registering this parent alone made a fiber that iterates a
                // result set suspend once per row: 1+N statements inside a
                // render for a loop that costs 1+1 outside one.
                $parents = [];
                foreach ($this->batchWith($parent) as $sibling) {
                    $siblingKey = $sibling->primaryKey();
                    if (!$this->hasRows($key, $siblingKey)) {
                        $parents[$siblingKey] = $sibling;
                    }
                }
                $parents[$groupKey] = $parent;

                $this->scheduler->await($key, $relation, $parents);
            } else {
                $this->loadRelationRows($relation, $this->batchWith($parent));
            }
        }

        // A batch that did not produce this group leaves the cache without the
        // key, and reading straight through would answer an EMPTY relation --
        // a silent wrong answer rather than a missing one. Load it directly
        // instead; the batch has already had its chance to be cheap.
        //
        // This parent alone, not its result set: if the set is what came back
        // empty, widening to it again would repeat the miss. A load outside
        // any batch, and recorded as one (LazyLoad::ALONE).
        if (!$this->hasRows($key, $groupKey)) {
            $this->loadRelationRows($relation, [$parent], $key);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->relationRows[$key][$groupKey] ?? [];

        return $this->hydrateAll($relation->target, $rows);
    }

    /**
     * A widened read's rows for $relation across $parent's result set: read
     * once for the whole set, as a scoped read caches them, and kept on the
     * result set rather than in the relation-row cache, which holds the
     * tenant's rows alone. Each parent read across tenants used to load
     * every sibling's rows again and keep only its own: N statements for N
     * parents, each returning every row.
     *
     * Kept under the widened scope's name -- every tenant, or the tenants
     * of the subtree in force -- so a read in another scope, the tenant's
     * own after the block among them, never sees them; and against the
     * write generation, so a write made since has them read again.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function widenedRows(RelationMeta $relation, Model $parent, string $key): array
    {
        $set = $parent->resultSet();
        $parents = $this->batchWith($parent);
        if ($set === null || $set->all() === []) {
            return $this->loadRelationRows($relation, $parents, $key);
        }

        $subtree = $this->subtreeInForce();
        $scoped = ($subtree === null ? "\0across" : "\0subtree\0" . implode("\0", $subtree)) . "\0" . $relation->key();

        $kept = $set->widenedRows($scoped, $this->writeGeneration);
        if ($kept !== null) {
            return $kept;
        }

        $generation = $this->writeGeneration;
        $grouped = $this->loadRelationRows($relation, $parents, $key);
        $set->keepWidenedRows($scoped, $generation, $grouped);

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @internal for the Orm's own relation loading and the batch
     *     scheduler's, inside this package
     */
    public function resolveBelongsToRow(RelationMeta $relation, Model $parent, string $foreignKey): ?array
    {
        $key = $this->relationCacheKey($relation);

        if ($this->isWidened()) {
            $grouped = $this->widenedRows($relation, $parent, $key);
            if (!\array_key_exists($foreignKey, $grouped)) {
                // The ghost's key is not one its siblings hold now: read it alone.
                $grouped = $this->loadRelationRows($relation, [], $key, keys: [$foreignKey]);
            }

            /** @var array<string, mixed>|null */
            return $grouped[$foreignKey][0] ?? null;
        }

        if (!$this->hasRows($key, $foreignKey)) {
            $this->loadRelationRows($relation, $this->batchWith($parent), keys: [$foreignKey]);
        }

        /** @var array<string, mixed>|null */
        return $this->relationRows[$key][$foreignKey] ?? null;
    }

    /**
     * Loads $relation for every one of $parents in one statement and puts
     * what it read in each: a collection filled, a #[BelongsTo] ghost filled
     * from its row. For Query::with(), so a relation the code is about to
     * read is not a lazy load at all.
     *
     * Through the same path a lazy load takes -- loadRelationRows(), under
     * the related model's tenant predicate, cached under the tenant's key
     * unless the read is widened -- and hydrated in one result set, so a
     * relation read on what it loaded loads for all of it. Not recorded as a
     * lazy load (LazyLoads): nothing lazy asked for it.
     *
     * A relation already loaded on a parent is left as it is. A #[BelongsTo]
     * whose row is not there to read -- dangling, or another tenant's -- is
     * left a ghost, which throws DanglingRelation when read, as it would
     * have without this.
     *
     * @param list<Model> $parents
     * @return list<Model> the related models, each once: the parents of a nested load
     *
     * @internal for the query builder, inside this package
     */
    public function eagerLoad(RelationMeta $relation, array $parents): array
    {
        if ($parents === []) {
            return [];
        }

        $key = $this->relationCacheKey($relation);
        $widened = $this->isWidened();
        $many = $relation->kind->collects();
        $loaded = $this->loadRelationRows($relation, $parents, $key, lazy: false);

        // Each parent's rows: what this load read, and -- scoped, where the
        // cache is the tenant's own -- what an earlier load had cached.
        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($parents as $parent) {
            $group = $many ? $parent->primaryKey() : (string) ($parent->foreignKey($relation->property) ?? '');
            if ($group === '' || isset($groups[$group])) {
                continue;
            }

            if (isset($loaded[$group])) {
                $groups[$group] = $many ? $loaded[$group] : \array_slice($loaded[$group], 0, 1);
            } elseif (!$widened && $this->hasRows($key, $group)) {
                /** @var list<array<string, mixed>>|array<string, mixed>|null $cached */
                $cached = $this->relationRows[$key][$group];
                /** @var list<array<string, mixed>> $rows */
                $rows = $many ? ($cached ?? []) : ($cached === null ? [] : [$cached]);
                $groups[$group] = $rows;
            } else {
                $groups[$group] = [];
            }
        }

        $target = Metadata::for($relation->target);
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                $rows[(string) ($row[$target->idColumn] ?? '')] = $row;
            }
        }

        $byId = [];
        foreach ($this->hydrateAll($relation->target, array_values($rows)) as $model) {
            $byId[$model->primaryKey()] = $model;
        }

        $owner = Metadata::for($relation->owner);
        $property = new \ReflectionProperty($relation->owner, $relation->property);
        foreach ($parents as $parent) {
            if ($many) {
                if (!$property->isInitialized($parent)) {
                    continue;
                }
                $collection = $owner->get($parent, $relation->property);
                if (!$collection instanceof Collection || $collection->isLoaded()) {
                    continue;
                }

                $items = [];
                foreach ($groups[$parent->primaryKey()] ?? [] as $row) {
                    $item = $byId[(string) ($row[$target->idColumn] ?? '')] ?? null;
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
                $collection->replaceItems($items);
                continue;
            }

            $foreignKey = $parent->foreignKey($relation->property);
            $related = $foreignKey === null ? null : ($byId[(string) $foreignKey] ?? null);
            if ($related === null) {
                continue;
            }

            // Scoped, the ghost in the property is the identity map's, and
            // hydrateAll() above filled it in place. Widened, it is a ghost
            // of its own the map never held, so the model read takes its
            // place -- standing for the same key.
            if ($widened && (!$property->isInitialized($parent) || $owner->get($parent, $relation->property) !== $related)) {
                $owner->set($parent, $relation->property, $related);
                $parent->rememberForeignKey($relation->property, $foreignKey, $related);
            }
        }

        return array_values($byId);
    }

    /**
     * One statement for every parent handed in. This is the whole point of the
     * batching: the group may span result sets and query calls.
     *
     * Returns the rows it grouped, keyed by group key, so a caller that
     * cannot use the cache still gets its answer. An unscoped read is exactly
     * that caller: these rows were read with no tenant predicate, so caching
     * them would serve them to a later scoped read -- but refusing to cache
     * them used to mean refusing to ANSWER, because both resolvers read their
     * result back out of the cache. acrossTenants() therefore returned an
     * empty collection for every relation.
     *
     * @param list<Model> $parents
     * @param LazyLoad::RENDER|null $batch how the parents were chosen, when the caller
     *        knows better than the parents can say: the scheduler, which hands in
     *        every parent a tick awaited. Otherwise read from the parents (batchOf())
     *        -- and only when a test records lazy loads.
     * @param bool $lazy whether a lazy read asked for it, and so goes on the record a
     *        test reads; an eager load (Query::with()) is a statement of the query's own
     * @param list<string> $keys further #[BelongsTo] keys to read beside the parents': a
     *        ghost's own, which its parent no longer holds once a write moved it
     * @return array<string, list<array<string, mixed>>>
     *
     * @internal for the Orm's own relation loading and the batch
     *     scheduler's, inside this package
     */
    public function loadRelationRows(RelationMeta $relation, array $parents, ?string $key = null, ?string $batch = null, bool $lazy = true, array $keys = []): array
    {
        // The scheduler passes the key the awaiting fiber computed, because
        // the tenant it was namespaced under is the one being re-entered, not
        // whatever is live in the context that happens to call this.
        $key ??= $this->relationCacheKey($relation);
        $groupKeys = [];

        // Unscoped, the cache is not consulted either. It holds what the
        // tenant's scope let through -- an empty group, or a miss where the
        // scope hid the row -- and an unscoped read answers from this load
        // alone, so skipping a group cached under scope answered NOTHING for
        // it: an empty collection, or a DanglingRelation for a row that is
        // there, inside acrossTenants().
        $unscoped = $this->isWidened();

        foreach ($parents as $parent) {
            $groupKey = $relation->kind->collects()
                ? $parent->primaryKey()
                : (string) ($parent->foreignKey($relation->property) ?? '');

            if ($groupKey !== '' && ($unscoped || !$this->hasRows($key, $groupKey))) {
                $groupKeys[$groupKey] = true;
            }
        }
        // A #[BelongsTo] ghost's own key, which its parent may no longer hold:
        // a write remembers the key it wrote, so a parent read before its
        // child moved is named by the key it was made with, not the child's.
        foreach ($keys as $groupKey) {
            if ($groupKey !== '' && ($unscoped || !$this->hasRows($key, $groupKey))) {
                $groupKeys[$groupKey] = true;
            }
        }

        if ($groupKeys === []) {
            return [];
        }

        // A statement is about to run: told to a test recording lazy loads,
        // with whether it ran in a batch. Never evaluated otherwise.
        if ($lazy && ($this->lazyLoads !== null || $this->lazyLoadFlag !== null)) {
            $load = new LazyLoad(
                $relation->key(),
                \count($parents),
                $batch ?? self::batchOf($parents),
                $this->lazyLoadFlag === null ? '' : self::callSite(),
            );
            $this->lazyLoads?->record($load);
            if ($this->lazyLoadFlag !== null) {
                ($this->lazyLoadFlag)($load);
            }
        }

        $ids = array_keys($groupKeys);
        $targetMeta = Metadata::for($relation->target);
        if ($relation->pivot !== null) {
            $grouped = $this->loadLinkedRows($relation, $relation->pivot, $targetMeta, array_map(strval(...), $ids));
            if (!$unscoped) {
                foreach ($ids as $id) {
                    $this->relationRows[$key][$id] = $grouped[$id] ?? [];
                }
            }

            return $grouped;
        }
        // A HasMany child's foreign key is declared on the owner's relation,
        // not in the child's own metadata, so it is allowed explicitly. It is
        // still compiled metadata; it just lives on the other side.
        $childColumn = self::identifier($targetMeta, $relation->childColumn(), [$relation->foreignKey]);

        // A #[HasMany] passes its trashed children by, as any read does; a
        // #[BelongsTo] reads the row its key names, trashed or not, so a
        // child whose parent was deleted still says whose it was (ADR 0191).
        [$scope, $scopeBindings] = $relation->kind === RelationKind::HasMany
            ? $this->readScope($targetMeta, Trashed::Without)
            : $this->tenantScope($targetMeta);

        // maxValues() ids at a time, so a result set of any size loads:
        // one list of them all outgrew what a driver binds. Each group's
        // rows come from the one statement its id was in, so a relation's
        // order holds within it.
        $grouped = [];
        foreach (array_chunk($ids, $this->maxValues()) as $chunk) {
            $rows = $this->connection->select(
                // The join column has to come back even when the child does
                // not declare it: it is what the rows are grouped by.
                $this->selectSql($targetMeta, $childColumn, \count($chunk), $relation->orderBy, [$childColumn], $scope),
                [...$chunk, ...$scopeBindings],
            );

            foreach ($rows as $row) {
                $grouped[(string) ($row[$childColumn] ?? '')][] = $row;
            }
        }

        // Not cached while unscoped, for the same reason the identity map is
        // bypassed: these rows were read without a tenant predicate, and a
        // later scoped read must not be served one of them. The rows are
        // still RETURNED, so the caller that asked for them can use them.
        if ($unscoped) {
            return $grouped;
        }

        foreach ($ids as $id) {
            $this->relationRows[$key][$id] = $relation->kind->collects()
                ? ($grouped[$id] ?? [])
                : ($grouped[$id][0] ?? null);
        }

        return $grouped;
    }

    /**
     * A #[BelongsToMany]'s rows for $ids, the owners' keys, grouped by them
     * (ADR 0192): one statement for each maxValues() of them, the
     * target's table joined to the pivot's, each held to its own tenant
     * predicate -- so a pivot row of another tenant's never links a row,
     * nor does one of the tenant's own reach a target of another's.
     *
     * In the relation's order, then the pivot's key: the order the rows
     * were linked in as far as the keys are minted in order -- an
     * auto-increment always, UUIDv7 to the millisecond.
     *
     * @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadLinkedRows(RelationMeta $relation, PivotMeta $pivot, ModelMeta $target, array $ids): array
    {
        $pivotMeta = Metadata::for($pivot->class);
        $owner = self::identifier($pivotMeta, $pivot->ownerColumn);
        $other = self::identifier($pivotMeta, $pivot->targetColumn);
        $order = $relation->orderBy === null ? [] : ['"t"."' . self::identifier($target, $relation->orderBy) . '"'];
        $order[] = '"p"."' . $pivotMeta->idColumn . '"';

        $columns = implode(', ', array_map(static fn (string $c): string => '"t"."' . $c . '"', $target->selectColumns()));
        // A trashed target, and a trashed pivot row, link nothing (ADR 0191).
        [$pivotScope, $pivotBindings] = $this->readScope($pivotMeta, Trashed::Without, 'p');
        [$targetScope, $targetBindings] = $this->readScope($target, Trashed::Without, 't');

        $grouped = [];
        foreach (array_chunk($ids, $this->maxValues()) as $chunk) {
            $sql = "SELECT {$columns}, \"p\".\"{$owner}\" AS \"" . self::LINKED_BY . "\""
                . " FROM \"{$target->table}\" AS \"t\" JOIN \"{$pivotMeta->table}\" AS \"p\" ON \"p\".\"{$other}\" = \"t\".\"{$target->idColumn}\""
                . " WHERE \"p\".\"{$owner}\" IN (" . implode(', ', array_fill(0, \count($chunk), '?')) . ')'
                . ($pivotScope === '' ? '' : " AND {$pivotScope}")
                . ($targetScope === '' ? '' : " AND {$targetScope}")
                . ' ORDER BY ' . implode(', ', $order);

            foreach ($this->connection->select($sql, [...$chunk, ...$pivotBindings, ...$targetBindings]) as $row) {
                $by = (string) ($row[self::LINKED_BY] ?? '');
                unset($row[self::LINKED_BY]);
                $grouped[$by][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Every #[BelongsToMany] loaded through a pivot that was written, or of
     * a target that was, read again when next asked for (ADR 0192): its
     * cached rows dropped, in every tenant's cache, and each loaded
     * collection of it unloaded -- each the identity map holds in the scope
     * in force, and $owner, the model attach(), detach() or sync() was
     * handed, which the map may not hold.
     *
     * Dropped rather than patched, as a #[HasMany] is: a pivot row deleted
     * is known by its key alone, and which owner it linked is not.
     *
     * @internal for the Orm's own writes and Pivots, inside this package
     */
    public function forgetLinks(RelationMeta $relation, ?Model $owner = null): void
    {
        $suffix = "\0" . $relation->key();
        foreach (array_keys($this->relationRows) as $key) {
            if (str_ends_with($key, $suffix)) {
                unset($this->relationRows[$key]);
            }
        }

        // The identity map's models in the scope in force are enough: a
        // pivot is linked in one tenant (attach(), detach() and sync()
        // refuse a widened scope), and the map is flushed at every actAs() and acrossTenants()
        // boundary, so no other tenant's bucket holds a model loaded
        // before this write that is read after it. The relation-row cache,
        // which outlives those, is dropped above for every tenant.
        $models = [];
        foreach ($this->identityMap->all() as $model) {
            if ($model instanceof $relation->owner
                && $this->identityMap->get($relation->owner, $model->primaryKey()) === $model) {
                $models[] = $model;
            }
        }
        if ($owner instanceof $relation->owner && !\in_array($owner, $models, true)) {
            $models[] = $owner;
        }

        $property = new \ReflectionProperty($relation->owner, $relation->property);
        $meta = Metadata::for($relation->owner);
        foreach ($models as $model) {
            // Not a ghost, whose relations are not installed yet.
            if (!self::holdsRow($model) || !$property->isInitialized($model)) {
                continue;
            }
            $collection = $meta->get($model, $relation->property);
            if ($collection instanceof Collection && $collection->isLoaded()) {
                $collection->unload($this->hasManyLoader(
                    $relation,
                    $model,
                    $this->isUnscoped() ? null : $this->captureScope(),
                    $this->scopeName(),
                ));
            }
        }
    }

    /**
     * Links $owner to the rows of its #[BelongsToMany] $relation that
     * $related names -- models of the target, or their keys -- each that is
     * not linked already, by saving a pivot model for each, with $values
     * for the pivot's own properties (ADR 0192). In one transaction, and
     * only once the owner and every row named are read in the tenant in
     * force: one that is not -- missing, or another tenant's -- is refused
     * with MissingRow, worded alike for both, and nothing is written.
     *
     * @param iterable<Model|string|int> $related
     * @param array<string, mixed> $values the pivot's properties, for the links made
     * @throws MissingRow   naming a row the tenant in force does not read
     * @throws \LogicException across tenants or a subtree, or for an owner never saved
     */
    public function attach(Model $owner, string $relation, iterable $related, array $values = []): PivotChange
    {
        return new Pivots($this)->write('attach()', $owner, $relation, $related, $values, attach: true, detach: false);
    }

    /**
     * Unlinks $owner from the rows of its #[BelongsToMany] $relation that
     * $related names, each that is linked, by deleting its pivot model --
     * held as attach() is.
     *
     * @param iterable<Model|string|int> $related
     */
    public function detach(Model $owner, string $relation, iterable $related): PivotChange
    {
        return new Pivots($this)->write('detach()', $owner, $relation, $related, [], attach: false, detach: true);
    }

    /**
     * Links $owner to exactly the rows $related names: attach()es what is
     * not linked, with $values, and detach()es what is linked and not
     * named -- in one transaction, held as attach() is. An empty $related
     * unlinks every row.
     *
     * @param iterable<Model|string|int> $related
     * @param array<string, mixed> $values the pivot's properties, for the links made
     */
    public function sync(Model $owner, string $relation, iterable $related, array $values = []): PivotChange
    {
        return new Pivots($this)->write('sync()', $owner, $relation, $related, $values, attach: true, detach: true);
    }

    /**
     * Whether a Level 1 load handed $parents ran in a batch, for the record a
     * test reads (LazyLoad).
     *
     * In a batch when it was for several parents -- batchWith()'s result set,
     * or what of it had not loaded the relation -- or for one parent that is
     * the whole of the result set it was hydrated in: a statement that
     * returned one row, read by one load, is batched as far as it can be.
     * Alone when it was for one parent that has no result set of rows to
     * widen to -- a ghost, filled by itself, or a model whose set is empty
     * or missing -- or that has siblings it was not loaded with:
     * resolveHasMany()'s retry. Each is a statement per parent when it is
     * read in a loop.
     *
     * The one row a statement returned is told apart (LazyLoad::ONE_ROW):
     * batched as far as it can be by itself, it is a statement per row when
     * the statement is in a loop -- a find() for each of N keys -- which the
     * testing layer reads off how often one relation loads that way (ADR
     * 0122).
     *
     * @param list<Model> $parents
     * @return LazyLoad::RESULT_SET|LazyLoad::ONE_ROW|LazyLoad::ALONE
     */
    private static function batchOf(array $parents): string
    {
        if (\count($parents) !== 1) {
            return LazyLoad::RESULT_SET;
        }

        $set = $parents[0]->resultSet();

        return $set !== null && !$set->alone && $set->models === $parents ? LazyLoad::ONE_ROW : LazyLoad::ALONE;
    }

    /** @internal for the Orm's relation loading and the batch scheduler's */
    public function hasRows(string $relationKey, string $groupKey): bool
    {
        return \array_key_exists($groupKey, $this->relationRows[$relationKey] ?? []);
    }

    /** Drops every relation-row group belonging to one tenant. */
    private function forgetRelationRows(string $tenant): void
    {
        $prefix = $tenant . "\0";

        foreach (array_keys($this->relationRows) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->relationRows[$key]);
            }
        }
    }

    /**
     * The relation-row cache key, namespaced by tenant.
     *
     * RelationMeta::key() identifies the relation, not whose rows they are.
     * Unnamespaced, a relation loaded under one tenant answered the same
     * relation read under another -- and the batch scheduler, which groups by
     * the same key, would have merged two fibers in different tenants into one
     * statement under whichever scope happened to be live at flush time.
     *
     * @internal for the Orm's relation loading and the batch scheduler's
     */
    public function relationCacheKey(RelationMeta $relation): string
    {
        return $this->tenant() . "\0" . $relation->key();
    }

    // ------------------------------------------------------------------- sql

    /**
     * Vets a column name before it is interpolated into SQL.
     *
     * tinvar-spec.md, "Identifiers from users": "The query builder rejects
     * identifiers that don't come from compiled metadata, so no one can sort
     * by a hidden column."
     *
     * This is the half of that guarantee the builder can give: no identifier
     * outside the compiled metadata reaches SQL. It cannot tell a column the
     * page shows from a mapped one no Data class exposes, so it does not stop
     * a sort by a hidden column on its own. The other half is the caller's:
     * a sort or filter field from a request is a backed enum or an #[In] list
     * before it gets here. Nothing in the framework passes request input as
     * an identifier: its sorts come from #[HasMany], and the columns Exists
     * and Unique filter by come from their attributes.
     *
     * Two checks, deliberately. Membership is the real one. The shape check
     * behind it means that even a caller passing something from compiled
     * metadata that somehow is not an identifier cannot reach the query --
     * an injected identifier binds looser than the appended tenant predicate
     * and would defeat tenant scoping as well as read hidden columns.
     *
     * @param list<string> $alsoAllowed identifiers compiled elsewhere in the metadata
     */
    private static function identifier(ModelMeta $meta, string $column, array $alsoAllowed = []): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $column) !== 1) {
            throw MappingException::malformedIdentifier($column);
        }

        $known = [...$meta->knownIdentifiers(), ...$alsoAllowed];
        if (!\in_array($column, $known, true)) {
            throw MappingException::unknownIdentifier($meta->class, $column, $known);
        }

        return $column;
    }

    /** @param list<string> $extraColumns */
    private function selectSql(
        ModelMeta $meta,
        string $column,
        int $count,
        ?string $orderBy,
        array $extraColumns = [],
        string $scope = '',
    ): string {
        $select = $meta->selectColumns();
        foreach ($extraColumns as $extra) {
            if (!\in_array($extra, $select, true)) {
                $select[] = $extra;
            }
        }
        $columns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $select));
        $placeholders = implode(', ', array_fill(0, $count, '?'));
        $sql = "SELECT {$columns} FROM \"{$meta->table}\" WHERE \"{$column}\" IN ({$placeholders})";

        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY \"{$orderBy}\"";
        }

        return $sql;
    }
}
