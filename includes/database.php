<?php
/**
 * includes/database.php
 *
 * Plain PDO/SQLite connection — no external DB server, no Composer.
 * Works on any PHP 7.4+ host with the pdo_sqlite extension enabled
 * (this is compiled into PHP by default on nearly every shared host).
 *
 * On first run, if the .sqlite file doesn't exist yet, this creates it
 * and applies schema.sql automatically — no manual "import the schema"
 * step needed.
 */

require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $isNew = !file_exists(DB_PATH);

        // Make sure the containing folder exists.
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        try {
            $this->connection = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->connection->exec('PRAGMA foreign_keys = ON;');
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'System temporarily unavailable.']));
        }

        if ($isNew) {
            $this->applySchema();
        }
    }

    private function applySchema() {
        $schemaPath = __DIR__ . '/../schema.sql';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException('schema.sql not found — cannot initialise database.');
        }
        $sql = file_get_contents($schemaPath);
        $this->connection->exec($sql);
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
