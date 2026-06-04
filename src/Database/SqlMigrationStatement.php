<?php

declare(strict_types=1);

namespace App\Database;

use PDOException;

/**
 * Classifies MySQL errors from idempotent migration DDL so runners can continue safely.
 */
final class SqlMigrationStatement
{
    /**
     * Schema already matches the migration intent (partial apply, re-activate, manual fix).
     *
     * @see https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
     */
    public static function isBenignDuplicateSchemaError(PDOException $e): bool
    {
        $driverCode = 0;
        if (isset($e->errorInfo[1]) && is_int($e->errorInfo[1])) {
            $driverCode = $e->errorInfo[1];
        } elseif (isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            $driverCode = (int) $e->errorInfo[1];
        }

        return in_array($driverCode, [1050, 1060, 1061, 1091], true);
    }
}
