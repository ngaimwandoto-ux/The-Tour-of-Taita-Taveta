<?php
/**
 * includes/registration-columns.php
 * Adds the two new registration columns to an EXISTING database without
 * you running any migration by hand. Safe to call on every request:
 * it only alters the table if a column is missing.
 *
 *   team_id     — optional: which team the rider is racing for
 *   institution — College & University tier: the rider's institution
 */
function ensureRegistrationColumns(PDO $db): void {
    $have = [];
    foreach ($db->query("PRAGMA table_info(registrations)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $have[$col['name']] = true;
    }
    if (empty($have['team_id'])) {
        $db->exec("ALTER TABLE registrations ADD COLUMN team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL");
    }
    if (empty($have['institution'])) {
        $db->exec("ALTER TABLE registrations ADD COLUMN institution TEXT");
    }
}
