# Project File & Directory Structure: StockPlus (Paper Trading Simulator)

This document provides a comprehensive breakdown of every directory and file in the project, detailing their purpose, contents, and how they interact across the system (excluding `.env` and `.env.bak`).

---

## 🌳 High-Level Tree Overview

```
c:\xampp\htdocs\h\
├── .gitignore
├── analysis.php
├── buy.php
├── composer.json
├── composer.lock
├── dashboard.php
├── flow.readme
├── index.php
├── monitor_heartbeat.json
├── portfolio.php
├── README.md
├── requirements.txt
├── sell.php
├── stock_analysis.php
├── structure.md
├── test_analysis_verification.php
├── test_system.php
├── trade_history.php
│
├── .vscode/
│   └── settings.json
│
├── api/
│   ├── execute_stop_loss.php
│   ├── monitor_status.php
│   ├── stock_analysis.php
│   └── stock_price.php
│
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── app.js
│       └── chart.umd.min.js
│
├── config/
│   ├── database.php
│   └── database.php.bak
│
├── database/
│   ├── init_db.php
│   ├── init_db.php.bak
│   ├── schema.sql
│   ├── schema.sql.bak
│   └── simulator.sqlite
│
├── includes/
│   ├── footer.php
│   ├── functions.php
│   └── header.php
│
├── python/
│   ├── monitor_stop_loss.py
│   └── stock_data.py
│
├── stock_ui/
│   ├── index.html
│   └── style.css
│
└── vendor/
    ├── autoload.php
    ├── composer/
    └── phpmailer/
```

---

## 📁 Root-Level Files

| File | Type | Description & Purpose |
|---|---|---|
| **`.gitignore`** | Git Configuration | Specifies patterns for files and directories that Git should ignore and not commit (e.g., `.env`, `.env.bak`, `vendor/`, `__pycache__/`, and `.sqlite` runtime files). |
| **`analysis.php`** | PHP Web Entry | Lightweight router/redirect script that forwards requests directly to `stock_analysis.php`. |
| **`buy.php`** | PHP Web Page | Web interface for searching stocks, calculating total order cost, configuring simulated stop-loss percentages, and placing virtual buy orders. |
| **`composer.json`** | Project Metadata | Defines Composer dependencies for PHP, notably requiring `phpmailer/phpmailer` for SMTP email services. |
| **`composer.lock`** | Lockfile | Locks exact versions and commit hashes of all installed Composer packages and their dependencies. |
| **`dashboard.php`** | PHP Web Entry | Alias/redirect file that points users to the main dashboard (`index.php`). |
| **`flow.readme`** | Documentation | Comprehensive architectural and design document detailing end-to-end data flows, order execution steps, SQLite fallbacks, and test scenarios. |
| **`index.php`** | PHP Web Page | The primary application dashboard. Displays overall financial metrics (cash, portfolio value, unrealised & realised P&L), quick actions, live quote search, and recent trade logs. |
| **`monitor_heartbeat.json`** | JSON Runtime File | State file written by the Python background monitoring script to log its last heartbeat timestamp and active monitoring status. |
| **`portfolio.php`** | PHP Web Page | Portfolio management interface listing all open stock holdings, cost bases, live market values, profit/loss calculations, stop-loss threshold adjustments, and direct links to sell. |
| **`README.md`** | Documentation | Core repository documentation providing installation instructions, technology stack details, dependency requirements, and architecture summaries. |
| **`requirements.txt`** | Python Dependencies | Lists the Python libraries required by the bridge scripts, specifically `yfinance` for Yahoo Finance market data retrieval. |
| **`sell.php`** | PHP Web Page | Interface allowing users to sell partial or complete share quantities of an existing position at live market rates, crediting virtual proceeds and locking in realised P&L. |
| **`stock_analysis.php`** | PHP Web Page | Multi-dimensional analytics interface offering portfolio equity snapshots, date-range filtering, technical trend charts (Chart.js), individual equity metrics, and CSV reporting. |
| **`structure.md`** | Documentation | *(This file)* Comprehensive directory and file-level catalog explaining every component of the project. |
| **`test_analysis_verification.php`** | PHP Test Script | Automated test script that validates portfolio snapshot generation, analysis math, and date-range filtering. |
| **`test_system.php`** | PHP Test Script | End-to-end automated testing suite covering 11 critical trading, calculations, stop-loss triggers, database failover, and input validation scenarios. |
| **`trade_history.php`** | PHP Web Page | Historical transaction ledger showing all completed virtual buy, sell, and automatic stop-loss orders with execution prices, quantities, dates, and realised P&L. |

---

## 📂 Directories & Sub-Files

### 1. `.vscode/` (IDE Configuration)
Contains workspace-level developer settings for Visual Studio Code.
- **`settings.json`**: Configures editor preferences, PHP linting paths, and workspace formatting options.

---

### 2. `api/` (Asynchronous JSON REST Endpoints)
Lightweight backend scripts called asynchronously via JavaScript AJAX / `fetch()` calls:
- **`execute_stop_loss.php`**: Evaluates all open positions against live or simulated market prices and triggers virtual sell orders when a stop-loss price threshold is breached.
- **`monitor_status.php`**: Returns JSON data reflecting the health and last heartbeat of the background stop-loss monitoring process.
- **`stock_analysis.php`**: Serves JSON data for technical chart timelines and handles on-the-fly CSV report exports for the analysis page.
- **`stock_price.php`**: Accepts a stock symbol query, invokes the Python bridge (`python/stock_data.py`), caches recent prices in the database, and returns real-time market quotes.

---

### 3. `assets/` (Static Frontend Web Assets)
Contains user interface stylesheets, scripts, and libraries:
- **`assets/css/`**:
  - **`style.css`**: Master application stylesheet. Implements the Left-Sidebar dashboard layout and custom brand color palette (`#9FA0FF` Periwinkle, `#D5E8E6` Mint, `#FFF3E3` Warm Linen, `#FF7171` Coral Alert).
- **`assets/js/`**:
  - **`app.js`**: Core vanilla JavaScript. Powers live stock searching, dynamic order cost calculations, modal window controls, and periodic monitor status polling.
  - **`chart.umd.min.js`**: Standalone local build of Chart.js used for technical market charts and equity curve visualizations without external CDNs.

---

### 4. `config/` (System Configuration)
Contains environment variable loading and database connection logic:
- **`database.php`**: Database connection layer. Parses configuration from `.env`, initializes a PDO PostgreSQL connection, and provides automated, transparent SQLite fallback (`database/simulator.sqlite`) if PostgreSQL is unavailable.
- **`database.php.bak`**: Backup copy of previous database connection logic.

---

### 5. `database/` (Schemas, Migrations & SQLite Fallback)
Contains database definition files, initialization utilities, and local offline database storage:
- **`init_db.php`**: CLI setup script (`php database/init_db.php`) that connects to PostgreSQL, creates the target database if missing, and applies `schema.sql`.
- **`init_db.php.bak`**: Backup copy of previous database initialization script.
- **`schema.sql`**: Idempotent PostgreSQL DDL script creating all system tables:
  - `accounts`: Stores virtual cash balance and currency.
  - `positions`: Stores open stock quantities, average buy prices, and stop-loss rules.
  - `trades`: Ledger of all executed purchases, sales, and stop-loss events.
  - `price_cache`: Caches recent Yahoo Finance price lookups to reduce API latency.
  - `monitor_status`: Stores stop-loss robot running state and heartbeats.
  - `portfolio_snapshots`: Daily equity snapshots for portfolio performance tracking.
- **`schema.sql.bak`**: Backup copy of earlier database schema.
- **`simulator.sqlite`**: Local SQLite database file automatically created and used when running without a live PostgreSQL server.

---

### 6. `includes/` (Modular Shared Templates & Business Logic)
Reusable components included across web pages:
- **`header.php`**: Common page header template. Outputs the Left-Sidebar navigation menu (with brand logo, active route highlighting, and virtual cash snapshot), top notification banner, and top utility bar.
- **`footer.php`**: Common page footer template. Closes page layout tags, displays system timestamps, renders the stop-loss monitor instructions modal, and loads `assets/js/app.js`.
- **`functions.php`**: Core backend business logic library (over 58KB of validated functions). Handles:
  - Account balance management & simulation resets.
  - Buy/sell order validations & transaction management.
  - Position calculations (unrealised & realised P&L).
  - Background Python execution bridges for quote lookups.
  - SQLite and PostgreSQL query abstraction.

---

### 7. `python/` (Market Data Engine & Stop-Loss Daemon)
Python scripts bridging real-world financial data with the PHP application:
- **`monitor_stop_loss.py`**: Background CLI process (`python python/monitor_stop_loss.py`). Polls live prices every 30 seconds, compares current prices against position stop-loss thresholds, automatically executes stop-loss sell orders, and updates `monitor_heartbeat.json`.
- **`stock_data.py`**: Python script executed via `shell_exec` by PHP. Queries the Yahoo Finance API via `yfinance` to retrieve live stock quotes, company names, currency codes, and historical daily prices.

---

### 8. `stock_ui/` (Standalone UI Showcase & Prototype)
An independent, standalone mockup demonstrating the platform's visual design and interaction flow:
- **`index.html`**: Complete standalone single-page trading UI containing tabs for Overview, Watchlist, Portfolio, Order Desk, Technical Analysis, and Settings with interactive modals and Chart.js integration.
- **`style.css`**: Stylesheet importing the main application tokens and defining tab transition animations.

---

### 9. `vendor/` (Composer Third-Party Libraries)
Third-party libraries managed by Composer:
- **`autoload.php`**: Central PSR-4 autoloader for Composer packages.
- **`composer/`**: Composer internal configuration, package manifests, and class maps.
- **`phpmailer/phpmailer/`**: Full PHPMailer package used for sending transaction emails, verification codes, and alerts over Gmail SMTP.

---

## 🔄 Architectural Data Flow

```
[Web Browser / User]
       │
       ▼
 [PHP Web Pages] (index.php, buy.php, sell.php, portfolio.php, stock_analysis.php)
       │
       ├──> [includes/header.php & includes/footer.php] (Left-Sidebar Layout & Navigation)
       ├──> [assets/css/style.css & assets/js/app.js]   (UI Tokens, AJAX & Interactive Calculations)
       ├──> [includes/functions.php]                   (Core Financial Logic & DB Transactions)
       │           │
       │           ├──> [config/database.php]
       │           │          │
       │           │          ├──> [PostgreSQL Database (Primary)]
       │           │          └──> [database/simulator.sqlite (Zero-Config Fallback)]
       │           │
       │           └──> [python/stock_data.py]
       │                      │
       │                      ▼
       │               [Yahoo Finance API (yfinance)]
       │
       └──> [python/monitor_stop_loss.py] ──> (24/7 Automated Stop-Loss Trigger Robot)
```

---
*Generated: November 2026 &bull; Project: StockPlus Paper Trading Simulator*

