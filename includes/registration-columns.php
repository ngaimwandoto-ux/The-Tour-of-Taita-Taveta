<?php
/**
 * includes/registration-columns.php
 * Adds the new registration column to an EXISTING database without
 * you running any migration by hand. Safe to call on every request:
 * it only alters the table if a column is missing.
 *
 *   team_id     — optional: which team the rider is racing for
 */
function ensureRegistrationColumns(PDO $db): void {
    $have = [];
    foreach ($db->query("PRAGMA table_info(registrations)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $have[$col['name']] = true;
    }
    if (empty($have['team_id'])) {
        try {
            $db->exec("ALTER TABLE registrations ADD COLUMN team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL");
        } catch (PDOException $e) {
            // Two first-ever requests can reach this line together; the loser gets
            // "duplicate column" (or a split-second lock). The column exists either way.
            if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'locked') === false) throw $e;
        }
    }
}
