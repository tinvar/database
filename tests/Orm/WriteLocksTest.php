<?php

declare(strict_types=1);

/**
 * A write through the query builder locks the rows it writes and no
 * other: on MySQL, a plain FOR UPDATE locks every row of every table the
 * statement joins, so a write sorted or filtered through a #[BelongsTo]
 * path held a lock on each aisle it looked at, another tenant's included,
 * until its transaction ended, and that tenant's own writes to its own
 * aisle waited on it. The model's own rows are locked alone, as Postgres's
 * FOR UPDATE OF locks them.
 */

namespace Tinvar\Tests\Orm\WriteLocks;

use Tinvar\Orm\Change;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Orm\Fixtures\Shelf;
use Tinvar\Tests\Orm\Fixtures\Shelves;

require_once __DIR__ . '/Fixtures/shelves.php';

it('leaves another tenant\'s joined rows unlocked while a MySQL write through a join is open', function () {
    Shelves::onMySql(static function (Shelves $fixture, PdoConnection $connection): void {
        $orm = $fixture->orm(connection: $connection);
        $database = $fixture->pdo->query('SELECT DATABASE()')?->fetchColumn();
        $other = Databases::another('mysql');
        $other->exec("USE {$database}");
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $globexWrote = null;
        $orm->actAs('acme', static function () use ($orm, $other, $fixture, &$globexWrote): void {
            $orm->transaction(static function () use ($orm, $other, $fixture, &$globexWrote): void {
                // Foxtrot is acme's shelf on globex's aisle G1: the sort's
                // join looks at G1, and finds it no aisle of acme's.
                expect($orm->query(Shelf::class)->orderBy('aisle.code')->limit(10)->update(['position' => Change::add(1)]))->toBe(4);

                // Globex, meanwhile, renames its own aisle.
                try {
                    $globexWrote = $other->exec("UPDATE aisles SET code = 'G9' WHERE id = '{$fixture->ids['G1']}'");
                } catch (\PDOException $e) {
                    $globexWrote = $e->getMessage();
                }
            });
        });

        expect($globexWrote)->toBe(1);
    });
})->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
