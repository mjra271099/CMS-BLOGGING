<?php
declare(strict_types=1);

namespace App\Core;

use PDOException;

/**
 * Incremental database migrator. Runs numbered PHP migrations once and stores
 * the applied version. Safe to call on every request (cheap check).
 */
final class Migrator
{
    public static function run(bool $force = false): array
    {
        $executed = [];
        if (!Database::tableExists('schema_migrations')) {
            Database::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(64) NOT NULL PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        $applied = Database::run('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $files = glob(CMS_ROOT . '/database/migrations/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (!$force && in_array($version, $applied, true)) {
                continue;
            }
            $callback = require $file;
            if (is_callable($callback)) {
                $callback();
            }
            if (!in_array($version, $applied, true)) {
                Database::run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            }
            $executed[] = $version;
        }
        return $executed;
    }

    public static function pending(): array
    {
        $applied = Database::tableExists('schema_migrations')
            ? Database::run('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN)
            : [];
        $files = glob(CMS_ROOT . '/database/migrations/*.php') ?: [];
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (!in_array($version, $applied, true)) {
                $pending[] = $version;
            }
        }
        return $pending;
    }

    public static function applied(): array
    {
        if (!Database::tableExists('schema_migrations')) {
            return [];
        }
        return Database::run('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll();
    }

    /**
     * Execute schema.sql statements for a fresh installation.
     */
    public static function installSchema(): void
    {
        $schema = (string)file_get_contents(CMS_ROOT . '/database/schema.sql');
        foreach (self::splitStatements($schema) as $statement) {
            Database::pdo()->exec($statement);
        }
    }

    /**
     * Record a migration version as already applied, without running it.
     * Used after installSchema(), because schema.sql already contains the
     * result of every migration shipped with this release.
     */
    public static function markApplied(string $version): void
    {
        if (!Database::tableExists('schema_migrations')) {
            return;
        }
        Database::run(
            'INSERT IGNORE INTO schema_migrations (version) VALUES (?)',
            [$version]
        );
    }

    /**
     * Mark all migrations shipped in this release as applied (fresh install).
     */
    public static function markAllShippedAsApplied(): array
    {
        $marked = [];
        foreach (glob(CMS_ROOT . '/database/migrations/*.php') ?: [] as $file) {
            $version = basename($file, '.php');
            self::markApplied($version);
            $marked[] = $version;
        }
        return $marked;
    }

    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }
        return $statements;
    }

    /**
     * Add a column only when it is missing. Works on MySQL and MariaDB.
     */
    public static function addColumn(string $table, string $column, string $definition): void
    {
        if (!Database::tableExists($table) || Database::columnExists($table, $column)) {
            return;
        }
        try {
            Database::pdo()->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        } catch (PDOException $e) {
            Logger::warning('migration addColumn failed', ['table' => $table, 'column' => $column, 'error' => $e->getMessage()]);
        }
    }

    public static function addIndex(string $table, string $name, string $columns): void
    {
        if (Database::indexExists($table, $name)) {
            return;
        }
        try {
            Database::pdo()->exec(sprintf('ALTER TABLE `%s` ADD INDEX `%s` (%s)', $table, $name, $columns));
        } catch (PDOException $e) {
            Logger::warning('migration addIndex failed', ['table' => $table, 'index' => $name, 'error' => $e->getMessage()]);
        }
    }

    public static function addForeignKey(string $table, string $name, string $definition): void
    {
        try {
            $exists = Database::run(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = ?',
                [Config::db()['name'], $name]
            )->fetchColumn();
            if ((int)$exists === 0) {
                Database::pdo()->exec(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $name, $definition));
            }
        } catch (PDOException $e) {
            Logger::warning('migration addForeignKey failed', ['table' => $table, 'constraint' => $name, 'error' => $e->getMessage()]);
        }
    }
}
