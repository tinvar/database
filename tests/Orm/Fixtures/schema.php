<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Fixtures;

use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;

/**
 * A small SQLite database so ORM behaviour -- identity, batching, statement
 * counts -- is tested against real SQL without needing a server. The Racks
 * example and the benchmark run the same code against PostgreSQL.
 */
final class Fixture
{
    /** @var array{sites: list<string>, racks: list<string>, units: list<string>} */
    public array $ids = ['sites' => [], 'racks' => [], 'units' => []];

    public PdoConnection $connection;

    public function __construct(int $racks = 3, int $unitsPerRack = 4, int $sites = 1)
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, code TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, height_u INTEGER NOT NULL, site_id TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE units (id TEXT PRIMARY KEY, position INTEGER NOT NULL, name TEXT NOT NULL, rack_id TEXT NOT NULL)');

        for ($s = 0; $s < $sites; $s++) {
            $siteId = Uuid::v7();
            $this->ids['sites'][] = $siteId;
            $pdo->prepare('INSERT INTO sites VALUES (?, ?)')->execute([$siteId, 'LAX' . $s]);
        }

        for ($r = 0; $r < $racks; $r++) {
            $rackId = Uuid::v7();
            $this->ids['racks'][] = $rackId;
            $siteId = $this->ids['sites'][$r % $sites];
            $pdo->prepare('INSERT INTO racks VALUES (?, ?, ?, ?)')
                ->execute([$rackId, sprintf('R-%02d', $r + 1), 42, $siteId]);

            for ($u = 0; $u < $unitsPerRack; $u++) {
                $unitId = Uuid::v7();
                $this->ids['units'][] = $unitId;
                $pdo->prepare('INSERT INTO units VALUES (?, ?, ?, ?)')
                    ->execute([$unitId, $u + 1, sprintf('sw-%02d-%02d', $r + 1, $u + 1), $rackId]);
            }
        }

        $this->connection = new PdoConnection($pdo, 'test');
        $this->connection->resetStatementCount();
    }
}
