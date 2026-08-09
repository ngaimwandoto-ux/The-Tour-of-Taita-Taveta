-- =============================================
-- TOUR OF TAITA TAVETA — SQLITE SCHEMA
-- Applied automatically by includes/database.php on first run.
-- =============================================

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    account_type TEXT NOT NULL CHECK(account_type IN
        ('rider','team','sponsor','supporter','partner','volunteer','admin')),
    display_name TEXT NOT NULL,
    location TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    email_verified INTEGER NOT NULL DEFAULT 0,
    verification_token TEXT,
    reset_token TEXT,
    reset_expires TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TEXT
);

-- One row per account type, only the fields that type actually needs.
CREATE TABLE IF NOT EXISTS rider_profiles (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    category TEXT CHECK(category IN ('elite','amateur','open')),
    gender TEXT CHECK(gender IN ('men','women')),
    team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    captain_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS volunteer_profiles (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    primary_role TEXT CHECK(primary_role IN
        ('conservation','culture_dance','history_guide','security','race_day_logistics','general'))
);

CREATE TABLE IF NOT EXISTS sponsor_profiles (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    company_name TEXT NOT NULL,
    package_type TEXT
);

CREATE TABLE IF NOT EXISTS supporter_profiles (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    tier TEXT CHECK(tier IN ('tsavorite','ruby','green_garnet','spinel','rhodolite'))
);

CREATE TABLE IF NOT EXISTS partner_profiles (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    business_name TEXT NOT NULL,
    business_type TEXT
);

-- Race registrations — filled "pending" by mpesa-stk.php, flipped to
-- "paid"/"failed" by callback.php once Safaricom responds.
CREATE TABLE IF NOT EXISTS registrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    checkout_request_id TEXT UNIQUE,
    name TEXT NOT NULL,
    email TEXT,
    phone TEXT NOT NULL,
    ticket TEXT NOT NULL,
    leg TEXT NOT NULL,
    gender TEXT,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed')),
    mpesa_receipt TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Site visitation-fee payments — same pending/paid/failed lifecycle.
CREATE TABLE IF NOT EXISTS visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    checkout_request_id TEXT UNIQUE,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    site TEXT NOT NULL,
    leg TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed')),
    mpesa_receipt TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    action TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reg_status ON registrations(status);
CREATE INDEX IF NOT EXISTS idx_reg_leg ON registrations(leg);
CREATE INDEX IF NOT EXISTS idx_visits_status ON visits(status);
CREATE INDEX IF NOT EXISTS idx_visits_leg ON visits(leg);
CREATE INDEX IF NOT EXISTS idx_rate_limits_lookup ON rate_limits(ip_address, action, created_at);
