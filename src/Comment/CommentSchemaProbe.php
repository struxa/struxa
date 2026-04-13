<?php

declare(strict_types=1);

namespace App\Comment;

use PDO;
use PDOException;

/**
 * Caches whether optional comment schema from migration 032 is present (likes table, user_id column).
 * Avoids hard 500s when code is deployed before `database/migrations/032_comment_user_likes.sql` is applied.
 */
final class CommentSchemaProbe
{
    /** @var array<int, self> */
    private static array $byPdo = [];

    private bool $likesTable = false;

    private bool $commentsUserIdColumn = false;

    public static function forPdo(PDO $pdo): self
    {
        $id = spl_object_id($pdo);

        return self::$byPdo[$id] ??= new self($pdo);
    }

    private function __construct(PDO $pdo)
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $this->likesTable = $this->mysqlTableExists($pdo, 'cms_comment_likes');
                $this->commentsUserIdColumn = $this->mysqlColumnExists($pdo, 'cms_comments', 'user_id');
            } elseif ($driver === 'sqlite') {
                $this->likesTable = $this->sqliteTableExists($pdo, 'cms_comment_likes');
                $this->commentsUserIdColumn = $this->sqliteColumnExists($pdo, 'cms_comments', 'user_id');
            }
        } catch (PDOException) {
            $this->likesTable = false;
            $this->commentsUserIdColumn = false;
        }
    }

    public function likesTable(): bool
    {
        return $this->likesTable;
    }

    public function commentsUserIdColumn(): bool
    {
        return $this->commentsUserIdColumn;
    }

    private function mysqlTableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1'
        );
        $st->execute([':t' => $table]);

        return $st->fetchColumn() !== false;
    }

    private function mysqlColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1'
        );
        $st->execute([':t' => $table, ':c' => $column]);

        return $st->fetchColumn() !== false;
    }

    private function sqliteTableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = :n LIMIT 1"
        );
        $st->execute([':n' => $table]);

        return $st->fetchColumn() !== false;
    }

    private function sqliteColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->query('PRAGMA table_info(' . $this->sqliteQuoteIdent($table) . ')');
        if ($st === false) {
            return false;
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row) && isset($row['name']) && (string) $row['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    private function sqliteQuoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
