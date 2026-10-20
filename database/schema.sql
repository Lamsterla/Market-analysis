-- Stock Market Automated Simulator Database Schema
-- Target: PostgreSQL 12+
-- Idempotent schema: Preserves existing data, positions, trades, and balances when rerun.

-- 1. Virtual Account Balance
CREATE TABLE IF NOT EXISTS accounts (
    id SERIAL PRIMARY KEY,
    balance NUMERIC(15, 2) NOT NULL DEFAULT 100000.00 CHECK (balance >= 0),
    initial_balance NUMERIC(15, 2) NOT NULL DEFAULT 100000.00 CHECK (initial_balance >= 0),
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Open Stock Positions
CREATE TABLE IF NOT EXISTS positions (
    id SERIAL PRIMARY KEY,
    symbol VARCHAR(30) NOT NULL UNIQUE,
    company_name VARCHAR(255),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    average_buy_price NUMERIC(15, 2) NOT NULL CHECK (average_buy_price > 0),
    stop_loss_price NUMERIC(15, 2) DEFAULT NULL,
    stop_loss_percent NUMERIC(5, 2) DEFAULT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Trade History (Simulated Orders)
CREATE TABLE IF NOT EXISTS trades (
    id SERIAL PRIMARY KEY,
    symbol VARCHAR(30) NOT NULL,
    company_name VARCHAR(255),
    trade_type VARCHAR(10) NOT NULL CHECK (trade_type IN ('BUY', 'SELL')),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    price NUMERIC(15, 2) NOT NULL CHECK (price > 0),
    total_amount NUMERIC(15, 2) NOT NULL CHECK (total_amount > 0),
    realised_pnl NUMERIC(15, 2) DEFAULT 0.00,
    reason VARCHAR(100) NOT NULL DEFAULT 'MANUAL',
    notes TEXT DEFAULT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. Price Cache for quick dashboard rendering
CREATE TABLE IF NOT EXISTS price_cache (
    symbol VARCHAR(30) PRIMARY KEY,
    company_name VARCHAR(255),
    price NUMERIC(15, 2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    last_updated TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 5. Automatic Stop-Loss Monitor Heartbeat / State
CREATE TABLE IF NOT EXISTS monitor_status (
    id INTEGER PRIMARY KEY DEFAULT 1,
    is_running BOOLEAN DEFAULT FALSE,
    last_heartbeat TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    last_check_symbols TEXT DEFAULT NULL,
    message TEXT DEFAULT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Seed default initial account with ₹100,000 virtual balance if not already present
INSERT INTO accounts (id, balance, initial_balance, currency)
VALUES (1, 100000.00, 100000.00, 'INR')
ON CONFLICT (id) DO NOTHING;

-- Seed initial monitor record if not already present
INSERT INTO monitor_status (id, is_running, message)
VALUES (1, FALSE, 'Stop-loss monitor is inactive')
ON CONFLICT (id) DO NOTHING;

-- Indexing for high performance queries
CREATE INDEX IF NOT EXISTS idx_trades_symbol ON trades (symbol);
CREATE INDEX IF NOT EXISTS idx_trades_created_at ON trades (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_positions_symbol ON positions (symbol);

-- 6. Historical Portfolio Snapshots for Equity Curve Tracking
CREATE TABLE IF NOT EXISTS portfolio_snapshots (
    id SERIAL PRIMARY KEY,
    snapshot_date DATE NOT NULL UNIQUE,
    available_cash NUMERIC(15, 2) NOT NULL,
    portfolio_value NUMERIC(15, 2) NOT NULL,
    total_equity NUMERIC(15, 2) NOT NULL,
    total_invested NUMERIC(15, 2) NOT NULL,
    unrealised_pnl NUMERIC(15, 2) NOT NULL,
    realised_pnl NUMERIC(15, 2) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_portfolio_snapshots_date ON portfolio_snapshots (snapshot_date ASC);

