<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin, strictly-typed PDO wrapper. Every query uses prepared statements.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = Config::db();
        $dsn = $db['socket']
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $db['socket'], $db['name'], $db['charset'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            Logger::exception($e);
            throw new RuntimeException('Koneksi database gagal. Periksa config.php.', 0, $e);
        }
        return self::$pdo;
    }

    public static function reset(?PDO $pdo = null): void
    {
        self::$pdo = $pdo;
        self::$transactionDepth = 0;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $key = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Insert tanpa data.');
        }
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::identifier($table),
            implode(', ', array_map([self::class, 'identifier'], $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $set = implode(', ', array_map(
            static fn(string $column): string => self::identifier($column) . ' = ?',
            array_keys($data)
        ));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::identifier($table), $set, $where);
        $stmt = self::run($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', self::identifier($table), $where);
        return self::run($sql, $params)->rowCount();
    }

    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    /**
     * Run a callback inside a transaction. Nested calls reuse savepoints.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $outermost = self::$transactionDepth === 0;
        if ($outermost) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . self::$transactionDepth);
        }
        self::$transactionDepth++;
        try {
            $result = $callback();
            self::$transactionDepth--;
            if ($outermost) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp_' . self::$transactionDepth);
            }
            return $result;
        } catch (Throwable $e) {
            self::$transactionDepth--;
            if ($outermost) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . self::$transactionDepth);
            }
            throw $e;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    public static function tableExists(string $table): bool
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([Config::db()['name'], $table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([Config::db()['name'], $table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function indexExists(string $table, string $index): bool
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
            );
            $stmt->execute([Config::db()['name'], $table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function _inTransactionCheck(): bool
    {
        return self::pdo()->inTransaction();
    }
}
