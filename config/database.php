<?php
/**
 * Database Connection Handler
 * Handles PDO connections to MySQL with fallback/support for SQLite in test suites.
 */

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;
    private static string $activeDriver = 'mysql';

    public static function getConnection(?string $driver = null): PDO {
        if ($driver !== null && $driver !== self::$activeDriver) {
            self::$instance = null;
            self::$activeDriver = $driver;
        }

        if (self::$instance === null) {
            $currentDriver = $driver ?: (getenv('DB_DRIVER') ?: DB_DRIVER);
            self::$activeDriver = $currentDriver;

            if ($currentDriver === 'sqlite') {
                $sqlitePath = getenv('SQLITE_PATH') ?: (ROOT_PATH . '/database_test.sqlite');
                $dsn = "sqlite:" . $sqlitePath;
                self::$instance = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                self::$instance->exec("PRAGMA foreign_keys = ON;");
            } else {
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );

                try {
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => true,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
                        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
                    ]);
                } catch (PDOException $e) {
                    // In CLI testing environments where MySQL is not yet configured,
                    // we allow a fallback to SQLite if explicit CLI test is requested.
                    if (php_sapi_name() === 'cli' && getenv('ALLOW_SQLITE_CLI_FALLBACK') === '1') {
                        $sqlitePath = ROOT_PATH . '/database_test.sqlite';
                        $dsn = "sqlite:" . $sqlitePath;
                        self::$instance = new PDO($dsn, null, null, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                        ]);
                        self::$instance->exec("PRAGMA foreign_keys = ON;");
                        self::$activeDriver = 'sqlite';
                    } else {
                        throw $e;
                    }
                }
            }
        }

        return self::$instance;
    }

    public static function getDriver(): string {
        return self::$activeDriver;
    }

    public static function setConnection(PDO $pdo, string $driver = 'sqlite'): void {
        self::$instance = $pdo;
        self::$activeDriver = $driver;
    }

    public static function reset(): void {
        self::$instance = null;
    }
}
