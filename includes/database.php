<?php
/**
 * includes/database.php
 *
 * Plain PDO/SQLite connection — no external DB server, no Composer.
 * Works on any PHP 7.4+ host with the pdo_sqlite extension enabled
 * (this is compiled into PHP by default on nearly every shared host).
 *
 * schema.sql is applied whenever the file changes (and on first run) — every
 * statement in it is "IF NOT EXISTS", so this is always safe and new tables
 * added to schema.sql later (like the messaging tables) still get created
 * automatically without a manual migration step.
 */

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        // Make sure the containing folder exists.
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $this->connection = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->connection->exec('PRAGMA foreign_keys = ON;');
            // Busy rush (e.g. many M-Pesa confirmations at once): wait up to 5 s for a
            // locked write instead of failing, and use write-ahead logging so readers
            // (stats, admin pages) don't block the writers and vice-versa.
            $this->connection->exec('PRAGMA busy_timeout = 5000;');
            try {
                $this->connection->exec('PRAGMA journal_mode = WAL;');
                $this->connection->exec('PRAGMA synchronous = NORMAL;');
            } catch (PDOException $e) {
                // Some file systems can't do WAL — carry on with the default mode.
                error_log('SQLite WAL not enabled: ' . $e->getMessage());
            }
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'System temporarily unavailable.']));
        }

        $this->applySchema();
    }

    private function applySchema() {
        $schemaPath = __DIR__ . '/../schema.sql';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException('schema.sql not found — cannot initialise database.');
        }
        $sql = file_get_contents($schemaPath);
        // Only run the schema when schema.sql has changed since last time (it is the same
        // ~40 statements otherwise, on every single request). The file's fingerprint is
        // kept in SQLite's built-in user_version slot.
        $version = crc32($sql) & 0x7fffffff;
        if ($version === 0) $version = 1;
        $current = (int) $this->connection->query('PRAGMA user_version')->fetchColumn();
        if ($current !== $version) {
            $this->connection->exec($sql);
            $this->connection->exec('PRAGMA user_version = ' . $version);
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }
}
