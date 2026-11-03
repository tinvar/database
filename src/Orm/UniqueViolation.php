<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Whether a statement's failure is a unique index refusing a row -- and
 * nothing else: not a NOT NULL, a foreign key or a lost session.
 *
 * For code whose check is the index itself: a store that inserts and
 * reports "taken" when the index says so, rather than asking first and
 * racing whoever asks at the same moment. Any other failure is not "taken"
 * and goes on as itself.
 *
 *  - Postgres: SQLSTATE 23505.
 *  - MySQL: SQLSTATE 23000 with ER_DUP_ENTRY (1062) or ER_DUP_ENTRY_WITH_KEY_NAME (1586).
 *  - SQLite: SQLSTATE 23000 with SQLITE_CONSTRAINT (19) and its UNIQUE or PRIMARY KEY message.
 *
 * Either driver's exception reads the same: the async driver's QueryError
 * carries the SQLSTATE and the server's code as PDO's does.
 */
final class UniqueViolation
{
    public static function is(\Throwable $failure): bool
    {
        if (!$failure instanceof \PDOException) {
            return false;
        }

        $state = (string) ($failure->errorInfo[0] ?? $failure->getCode());
        $code = $failure->errorInfo[1] ?? null;
        if ($state === '23505') {
            return true;
        }
        if ($state !== '23000') {
            return false;
        }
        if ($code === 1062 || $code === 1586 || $code === '1062' || $code === '1586') {
            return true;
        }

        return ($code === 19 || $code === '19' || $code === 2067 || $code === 1555)
            && preg_match('/UNIQUE constraint failed|PRIMARY KEY must be unique|is not unique/', $failure->getMessage()) === 1;
    }
}
