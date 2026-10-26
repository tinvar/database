<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Orm\Schema\Schema;

/**
 * One migration: a file in the application's migrations directory, named
 * for when it was made and what it does
 * (`2026_10_24_120000_create_racks.php`), returning an instance.
 *
 *     return new class extends Migration
 *     {
 *         public function up(Schema $schema): void
 *         {
 *             $schema->create('racks', function (Table $table): void { ... });
 *         }
 *
 *         public function down(Schema $schema): void
 *         {
 *             $schema->drop('racks');
 *         }
 *     };
 *
 * `make:migration` writes one from the difference between the models and
 * the database; a backfill is written by hand beside them
 * (tinvar-spec.md, "Migrations"). down() is optional: without one, the
 * migration cannot be rolled back, and a rollback that reaches it refuses
 * before running anything.
 */
abstract class Migration
{
    /**
     * Whether up() and down() run in a transaction where the database
     * allows it -- each recorded as run in the same transaction, so a
     * failure leaves neither the change nor the record. Off for a statement
     * that refuses one, such as Postgres's CREATE INDEX CONCURRENTLY.
     */
    public bool $withinTransaction = true;

    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
        throw new \LogicException('This migration has no down(); it cannot be rolled back.');
    }

    /** Whether this migration declares a down() of its own. */
    final public function reversible(): bool
    {
        return new \ReflectionMethod($this, 'down')->getDeclaringClass()->getName() !== self::class;
    }
}
