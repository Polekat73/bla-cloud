<?php
declare(strict_types=1);

namespace BlaCloud;

use PDO;

/** Thin PDO wrapper. Supports SQLite and MySQL/MariaDB. All queries use bound parameters. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connectWith(array $db): PDO
    {
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (($db['driver'] ?? '') === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'] ?? 'localhost', (int) ($db['port'] ?? 3306), $db['name'] ?? '');
            $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), $opts);
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        } else {
            $pdo = new PDO('sqlite:' . $db['path'], null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connectWith((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return (string) Config::get('db.driver', 'sqlite');
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
