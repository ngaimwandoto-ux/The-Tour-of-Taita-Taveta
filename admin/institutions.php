<?php
/**
 * includes/institutions.php
 * College & University team entries — one row per institution team, paid
 * by invoice / bank transfer (NOT M-Pesa). The table is created on first
 * use, so there is no migration to run by hand.
 *
 * status: pending   = entry received, invoice not yet paid (never counts)
 *         paid      = you confirmed the money arrived (counts toward the pool)
 *         cancelled = withdrawn
 * amount is filled in by YOU when you confirm payment (KES received; for a
 * USD transfer, the KES value that reached the account).
 */
function ensureInstitutionTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS institution_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            institution TEXT NOT NULL,
            team_name TEXT NOT NULL,
            contact_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            riders INTEGER NOT NULL DEFAULT 1,
            leg TEXT NOT NULL,
            leg_assumed INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','cancelled')),
            amount INTEGER,
            payment_ref TEXT,
            notes TEXT,
            ip TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at TEXT
        )
    ");
}
