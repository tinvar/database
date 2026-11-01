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
     * with where it was made: the dev toolbar's, under `tinvar dev`
     * (flagLazyLoads(), flagAcrossTenants()). Null otherwise, so a request
     * pays a null check and no backtrace.
     *
     * @var (\Closure(LazyLoad): void)|null
     */
    private ?\Closure $lazyLoadFlag = null;

    /** @var (\Closure(string): void)|null given the call's site, as file:line */
    private ?\Closure $acrossTenantsFlag = null;

    /**
     * What observeWrites() was handed: told of each row written, by class
     * and key, once the write is made. Kept across flush(), like the flags.
     *
     * @var (\Closure(class-string<Model>, string, bool): void)|null
     */
    private ?\Closure $writeObserver = null;

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
     * Where each change to an #[Audited] model is recorded (ADR 0150), or
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
     *        32 levels says "not under", a subtree of more than 1,000 tenants
     *        throws -- hold whatever an implementation returns (ADR 0056).
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
     * Records each change to an #[Audited] model with $changes (ADR 0150):
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
                . 'audit log over this Orm (new Tinvar\\Audit\\AuditLog($orm, ...)), which registers itself with recordChangesWith(), '
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

        $tenant = null;
        if ($meta->tenantColumn !== null) {
            $held = $after[$meta->tenantColumn] ?? $before[$meta->tenantColumn] ?? null;
            $tenant = \is_string($held) && $held !== '' ? $held : $this->tenant();
        }

        $this->changes->record(new ModelChange($kind, $meta->class, $id, $tenant, $changes));
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
        $this->rawStatements = $flag === null ? null : new RawStatements(
            $this->connection,
            function (string $sql, \Closure $site) use ($flag): void {
                $tables = RawSql::tenantTablesIn($sql, Metadata::tenantTables());
                if ($tables !== []) {
                    $flag(new RawStatement(RawSql::redacted($sql), $tables, $site(), $this->identityMap->tenant(), $this->isUnscoped()));
                }
            },
        );
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
     * row's key, and whether it was deleted. Null stops it. One observer:
     * a second call replaces the first.
     *
     * Told as the write is made, inside whatever transaction it is in, so
     * an observer that tells anyone else waits for the commit with
     * afterCommit(), as search's index does (ADR 0154): a write rolled back
     * then reaches nobody. Kept across flush(), which is a request's reset:
     * it is the application's wiring, not the request's state.
     *
     * @param (\Closure(class-string<Model>, string, bool): void)|null $observer
     */
    public function observeWrites(?\Closure $observer): void
    {
        $this->writeObserver = $observer;
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
     * and never the claim a token minted with no tenancy carries, which is
     * spelled with a control character (TenantBounds::isName()).
     */
    private static function assertTenantName(string $tenant): void
    {
        if ($tenant !== '' && !TenantBounds::isName($tenant)) {
            throw new \InvalidArgumentException('A tenant\'s name has no control character in it.');
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
     * @throws \LogicException where no membership granted it, with no tenant tree, or no tenant
     * @throws \LengthException for a subtree larger than TenantBounds::MAX_SUBTREE
     */
    public function withinSubtree(\Closure $work): mixed
    {
        $granted = $this->subtreeGrant();
        if ($granted === null) {
            throw new \LogicException(
                'withinSubtree() reads below the tenant in force, which only a membership grants: run it in a panel '
                . 'defined with `subtree: true`, whose membership check grants it.',
            );
        }
        if ($this->tenants === null) {
            throw new \LogicException('withinSubtree() reads across a tenant\'s subtree, and the Orm was given no tenant tree.');
        }

        $root = $this->tenant();
        if ($root === '') {
            throw new \LogicException('withinSubtree() reads below the tenant in force, and there is none.');
        }

        // The grant is the membership's, held in one tenant: it reaches that
        // tenant's subtree, from it or from a tenant below it, and no other.
        // actAs() into a tenant elsewhere reads that tenant -- and widens
        // nothing there.
        if ($root !== $granted && !$this->tenants->contains($granted, $root)) {
            throw new \LogicException(
                "withinSubtree() was granted below '{$granted}', and the tenant in force is not in its subtree.",
            );
        }

        return $this->inSubtree([$root, $this->tenants->subtree($root)], $work);
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
     * the tenant in force first -- or null when it reads one tenant, or
     * scoping is lifted.
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
     * @return array{string, list<string>}
     */
    private function tenantScope(ModelMeta $meta): array
    {
        $tenants = $this->tenantsInScope($meta);
        if ($tenants === null || $meta->tenantColumn === null) {
            return ['', []];
        }

        if (\count($tenants) > 1) {
            return [
                '"' . $meta->tenantColumn . '" IN (' . implode(', ', array_fill(0, \count($tenants), '?')) . ')',
                $tenants,
            ];
        }

        return ['"' . $meta->tenantColumn . '" = ?', $tenants];
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

        $subtree = $this->subtreeInForce();

        return $subtree !== null && \count($subtree) > 1 ? $subtree : [$tenant];
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

    /** @param class-string<Model> $class */
    public function find(string $class, string $id): ?Model
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
        if (!$this->isWidened() && ($existing = $this->identityMap->get($class, $id))) {
            /** @var Model $existing */
            if (self::holdsRow($existing)) {
                return $existing;
            }
        }
        $meta = Metadata::for($class);

        return $this->whereIn($class, $meta->idColumn, [$id])[0] ?? null;
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @param string|null     $orderBy any column the model maps, hidden ones
     *        included: a caller passing request input allowlists it first
     *        (see identifier())
     * @return list<T>
     */
    public function all(string $class, ?string $orderBy = null): array
    {
        $meta = Metadata::for($class);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

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
     * @return list<T>
     */
    public function whereIn(string $class, string $column, array $values, ?string $orderBy = null): array
    {
        if ($values === []) {
            return [];
        }
        $meta = Metadata::for($class);
        $column = self::identifier($meta, $column);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

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

        // Query::MAX_VALUES at a time, so no list outgrows what a driver
        // binds -- SQLite's 32,766 values, Postgres's and MySQL's 65,535 --
        // and each value once, or a value in two chunks came back twice. An
        // order is the database's, which only one statement keeps.
        $values = array_values(array_unique($values, \SORT_REGULAR));
        if ($orderBy !== null && \count($values) > Query::MAX_VALUES) {
            throw new \LengthException(
                'whereIn() orders at most ' . Query::MAX_VALUES . ' values, which one statement reads in the database\'s order: '
                . 'read more unordered, or page through them with query()->whereIn()->orderBy().',
            );
        }

        $rows = [];
        foreach (array_chunk($values, Query::MAX_VALUES) as $chunk) {
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
            if ($relation->kind === RelationKind::HasMany) {
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
     * the tenant, and whether withinSubtree() has widened it.
     */
    private function scopeName(): string
    {
        return $this->tenant() . ($this->subtreeInForce() === null ? '' : "\0subtree");
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
     *
     * @internal for the query builder's writes, inside this package
     */
    public function followStatementWrite(ModelMeta $meta, string $id, ?array $row, array $columns): void
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
        $this->followWrite($meta, $id, $written);
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
        // made in, so the two commit or roll back together (ADR 0150).
        $this->transaction(function () use ($model, $meta): void {
            $meta->beforeSave($model);
            $persisted = $model->isPersisted();
            $before = $model->snapshot();
            $pending = $persisted ? array_keys($model->changes()) : null;
            if ($persisted ? $this->update($model, $meta) : $this->insert($model, $meta)) {
                $this->recordChange(
                    $meta,
                    $persisted ? ModelChangeKind::Updated : ModelChangeKind::Created,
                    $meta->keyOf($model),
                    $persisted ? $before : null,
                    $model->snapshot(),
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
            $affected = $this->connection->execute(
                $sql,
                [...array_values($changes), $meta->keyOf($model), ...$scopeBindings],
            );

            // Nothing matched: the row is gone, or the tenant predicate excluded
            // it. Treated as success, the change was lost and the model marked
            // clean, so a retry sent nothing either. Thrown before markClean() so
            // the change stays pending. (This relies on the driver counting rows
            // matched, which SQLite and Postgres do; MySQL's PDO driver counts
            // rows changed unless MYSQL_ATTR_FOUND_ROWS is set.)
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
        $this->followWrite($meta, $meta->keyOf($model), $written);

        return true;
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
     * Such a model is deleted only where it has a row: one never saved, or
     * deleted already, is refused before any hook runs, and a DELETE that
     * removed nothing -- the row deleted since it was read, or out of the
     * tenant's reach, as a child's read inside withinSubtree() is after it
     * ends -- throws MissingRow inside the transaction, taking back what
     * #[BeforeDelete] wrote, and runs no #[AfterDelete]: a hook that hears
     * of a delete hears of one that happened.
     */
    public function delete(Model $model): void
    {
        $this->refuseWrite('delete()');

        $meta = Metadata::for($model::class);
        if (!$meta->hasDeleteHooks() && $meta->audited === null) {
            $this->deleteRow($model, $meta);

            return;
        }
        $this->refuseUnrecorded($meta);

        if (!$model->isPersisted()) {
            throw MissingRow::notPersisted($meta->class);
        }
        $this->transaction(function () use ($model, $meta): void {
            $meta->beforeDelete($model);
            $before = $model->snapshot();
            $hooks = $meta->hasDeleteHooks();
            $deleted = $this->deleteRow($model, $meta, mustMatch: $hooks);
            if ($deleted === 0 && $hooks) {
                throw MissingRow::onDelete($meta->class, $meta->keyOf($model));
            }
            // A delete that removed nothing -- the row gone already, or out
            // of the tenant's reach -- changed nothing to record.
            if ($deleted > 0) {
                $this->recordChange($meta, ModelChangeKind::Deleted, $meta->keyOf($model), $before, null);
            }
            $meta->afterDelete($model);
        });
    }

    /**
     * @param bool $mustMatch leave the model as it was where the DELETE removed nothing, for the caller to refuse
     * @return int the rows the DELETE removed
     */
    private function deleteRow(Model $model, ModelMeta $meta, bool $mustMatch = false): int
    {
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf('DELETE FROM "%s" WHERE "%s" = ?', $meta->table, $meta->idColumn);
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        // Read while the row is there to read.
        $this->noteRowTenants($meta, [$meta->keyOf($model)]);

        $deleted = $this->connection->execute($sql, [$meta->keyOf($model), ...$scopeBindings]);
        if ($deleted === 0 && $mustMatch) {
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
        if ($this->writeObserver !== null) {
            ($this->writeObserver)($meta->class, $id, $row === null);
        }

        foreach (Metadata::relationsTo($meta->class) as $relation) {
            if ($relation->kind === RelationKind::HasMany) {
                $this->followHasMany($relation, $meta, $id, $row);
            } else {
                $this->followBelongsTo($relation, $id, $row);
            }
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

            /** @var array<string, mixed>|null */
            return $grouped[$foreignKey][0] ?? null;
        }

        if (!$this->hasRows($key, $foreignKey)) {
            $this->loadRelationRows($relation, $this->batchWith($parent));
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
        $many = $relation->kind === RelationKind::HasMany;
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
     * @return array<string, list<array<string, mixed>>>
     *
     * @internal for the Orm's own relation loading and the batch
     *     scheduler's, inside this package
     */
    public function loadRelationRows(RelationMeta $relation, array $parents, ?string $key = null, ?string $batch = null, bool $lazy = true): array
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
            $groupKey = $relation->kind === RelationKind::HasMany
                ? $parent->primaryKey()
                : (string) ($parent->foreignKey($relation->property) ?? '');

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
        // A HasMany child's foreign key is declared on the owner's relation,
        // not in the child's own metadata, so it is allowed explicitly. It is
        // still compiled metadata; it just lives on the other side.
        $childColumn = self::identifier($targetMeta, $relation->childColumn(), [$relation->foreignKey]);

        [$scope, $scopeBindings] = $this->tenantScope($targetMeta);

        // Query::MAX_VALUES ids at a time, so a result set of any size loads:
        // one list of them all outgrew what a driver binds. Each group's
        // rows come from the one statement its id was in, so a relation's
        // order holds within it.
        $grouped = [];
        foreach (array_chunk($ids, Query::MAX_VALUES) as $chunk) {
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
            $this->relationRows[$key][$id] = $relation->kind === RelationKind::HasMany
                ? ($grouped[$id] ?? [])
                : ($grouped[$id][0] ?? null);
        }

        return $grouped;
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
