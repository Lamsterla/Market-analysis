-- StockPlus Authentication and Multi-User Schema
-- Target: PostgreSQL 12+ and SQLite compatible syntax where possible
-- Idempotent: Can be safely rerun without destroying existing tables or data.

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    user_id SERIAL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token_hash VARCHAR(64) DEFAULT NULL,
    verification_expiry TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    reset_token_hash VARCHAR(64) DEFAULT NULL,
    reset_expiry TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Index on email and tokens for fast lookups
CREATE INDEX IF NOT EXISTS idx_users_email ON users (email);
CREATE INDEX IF NOT EXISTS idx_users_verification_token ON users (verification_token_hash);
CREATE INDEX IF NOT EXISTS idx_users_reset_token ON users (reset_token_hash);

-- 2. Login Rate Limiting Table
CREATE TABLE IF NOT EXISTS login_attempts (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    attempt_time TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts (ip_address, attempt_time);

-- 3. Remember Me Persistent Tokens Table
CREATE TABLE IF NOT EXISTS remember_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_hash ON remember_tokens (token_hash);

-- 4. Multi-User Column Additions for Existing Tables (PostgreSQL idempotent DDL)
DO $$
BEGIN
    -- accounts.user_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'accounts' AND column_name = 'user_id'
    ) THEN
        ALTER TABLE accounts ADD COLUMN user_id INTEGER DEFAULT 1;
        CREATE INDEX IF NOT EXISTS idx_accounts_user_id ON accounts (user_id);
    END IF;

    -- positions.user_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'positions' AND column_name = 'user_id'
    ) THEN
        ALTER TABLE positions ADD COLUMN user_id INTEGER DEFAULT 1;
        -- Drop old single-symbol unique constraint if present
        ALTER TABLE positions DROP CONSTRAINT IF EXISTS positions_symbol_key;
        -- Create composite unique constraint per user and symbol
        CREATE UNIQUE INDEX IF NOT EXISTS idx_positions_user_symbol ON positions (user_id, symbol);
    END IF;

    -- trades.user_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'trades' AND column_name = 'user_id'
    ) THEN
        ALTER TABLE trades ADD COLUMN user_id INTEGER DEFAULT 1;
        CREATE INDEX IF NOT EXISTS idx_trades_user_id ON trades (user_id);
    END IF;

    -- portfolio_snapshots.user_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'portfolio_snapshots' AND column_name = 'user_id'
    ) THEN
        ALTER TABLE portfolio_snapshots ADD COLUMN user_id INTEGER DEFAULT 1;
        ALTER TABLE portfolio_snapshots DROP CONSTRAINT IF EXISTS portfolio_snapshots_snapshot_date_key;
        CREATE UNIQUE INDEX IF NOT EXISTS idx_portfolio_snapshots_user_date ON portfolio_snapshots (user_id, snapshot_date);
    END IF;
END $$;

