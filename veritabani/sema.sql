PRAGMA foreign_keys = ON;

-- Bus companies
CREATE TABLE IF NOT EXISTS bus_company (
    id            TEXT PRIMARY KEY,         -- UUID
    name          TEXT NOT NULL,
    logo_path     TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE IF NOT EXISTS user (
    id             TEXT PRIMARY KEY,        -- UUID
    full_name      TEXT NOT NULL,
    email          TEXT NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    role           TEXT NOT NULL CHECK (role IN ('USER','FIRMA_ADMIN','ADMIN')),
    company_id     TEXT NULL REFERENCES bus_company(id) ON DELETE SET NULL,
    balance_kurus  INTEGER NOT NULL DEFAULT 10000,  -- defaults to 100.00 TL
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Coupons (global or company specific)
CREATE TABLE IF NOT EXISTS coupons (
    id             TEXT PRIMARY KEY,        -- UUID
    code           TEXT NOT NULL UNIQUE,    -- e.g. "EKIM20"
    rate_or_kurus  INTEGER NOT NULL,        -- percentage (20) or fixed amount (kurus)
    kind           TEXT NOT NULL CHECK (kind IN ('YUZDE','SABIT')),
    usage_limit    INTEGER,                 -- NULL means unlimited total usage
    start_time     DATETIME,
    end_time       DATETIME,
    company_id     TEXT NULL REFERENCES bus_company(id) ON DELETE CASCADE,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User to coupon relation (who can use which coupon)
CREATE TABLE IF NOT EXISTS user_coupons (
    id         TEXT PRIMARY KEY,            -- UUID
    user_id    TEXT NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    coupon_id  TEXT NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
    used_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, coupon_id)
);

-- Trips
CREATE TABLE IF NOT EXISTS trips (
    id               TEXT PRIMARY KEY,      -- UUID
    company_id       TEXT NOT NULL REFERENCES bus_company(id) ON DELETE CASCADE,
    origin_city      TEXT NOT NULL,
    destination_city TEXT NOT NULL,
    departure_time   DATETIME NOT NULL,
    arrival_time     DATETIME NOT NULL,
    price_kurus      INTEGER NOT NULL,      -- base ticket price
    capacity         INTEGER NOT NULL,      -- total seats in the bus
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Reserved seats for a trip
CREATE TABLE IF NOT EXISTS booked_seats (
    id          TEXT PRIMARY KEY,           -- UUID
    trip_id     TEXT NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
    seat_number INTEGER NOT NULL,
    booked_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (trip_id, seat_number)
);

-- Tickets
CREATE TABLE IF NOT EXISTS tickets (
    id               TEXT PRIMARY KEY,      -- UUID
    trip_id          TEXT NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
    user_id          TEXT NOT NULL REFERENCES user(id) ON DELETE CASCADE,
    seat_number      INTEGER NOT NULL,      -- seat assigned to passenger
    status           TEXT NOT NULL CHECK (status IN ('ALINDI','IPTAL')),
    price_paid_kurus INTEGER NOT NULL,      -- amount paid after discounts
    coupon_code      TEXT,
    purchased_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    cancelled_at     DATETIME,
    pdf_path         TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (trip_id, seat_number)           -- prevent duplicate seat sales
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_user_email ON user(email);
CREATE INDEX IF NOT EXISTS idx_trips_times ON trips(departure_time);
CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id);
