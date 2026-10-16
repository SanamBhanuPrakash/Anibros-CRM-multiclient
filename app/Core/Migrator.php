<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Schema installer / migrator for MySQL / MariaDB: creates the database when the account may, applies database/schema.sql once, then any
 * database/migrations/NNN_name.sql in order (each recorded in schema_migrations with its checksum), then (re)creates the isolation views.
 * Used by bin/migrate.php and the web installer. Statements are split on ";" + newline (the SQL files contain no procedures).
 */
final class Migrator
{
    /** @return array{applied:string[],views:int} */
    public static function run(\PDO $pdo, bool $verbose = false): array
    {
        $applied = [];
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, checksum VARCHAR(64) NOT NULL, applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), execution_time_ms INT NOT NULL DEFAULT 0, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);

        $files = [['000_schema', APP_ROOT . '/database/schema.sql']];
        foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $f) $files[] = [basename($f, '.sql'), $f];
        foreach ($files as [$version, $file]) {
            if (in_array($version, $done, true)) continue;
            $sql = (string) file_get_contents($file);
            $t0 = microtime(true);
            foreach (self::split($sql) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    throw new \RuntimeException("Migration $version failed: " . $e->getMessage() . ' [' . substr($stmt, 0, 100) . ']', 0, $e);
                }
            }
            $st = $pdo->prepare('INSERT INTO schema_migrations (version, filename, checksum, execution_time_ms) VALUES (?, ?, ?, ?)');
            $st->execute([$version, basename($file), hash('sha256', $sql), (int) ((microtime(true) - $t0) * 1000)]);
            $applied[] = $version;
            if ($verbose) echo "applied $version\n";
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $views = Views::apply($pdo);
        return ['applied' => $applied, 'views' => $views];
    }

    /** @return string[] */
    public static function split(string $sql): array
    {
        $out = [];
        foreach (preg_split('/;\s*\n/', str_replace("\r\n", "\n", $sql)) ?: [] as $s) {
            $s = trim((string) preg_replace('/^--.*$/m', '', $s));
            if ($s !== '' && $s !== ';') $out[] = rtrim($s, ';');
        }
        return $out;
    }

    /** Connect without selecting a database and create it when missing (works on XAMPP; on cPanel the database already exists). */
    public static function ensureDatabase(array $db): \PDO
    {
        $dsn = !empty($db['socket']) ? 'mysql:unix_socket=' . $db['socket'] . ';charset=utf8mb4' : 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4';
        $pdo = new \PDO($dsn, (string) $db['user'], (string) $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => true]);
        $name = (string) $db['name'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new \InvalidArgumentException('Database name may only contain letters, digits and underscore');
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = " . $pdo->quote($name))->fetchColumn();
        if (!$exists) $pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . $name . '`');
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
