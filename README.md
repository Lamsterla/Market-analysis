# Stock Market Automated System (Paper Trading Simulator)

A functional web-based stock paper-trading simulator built with PHP, vanilla JavaScript, CSS, PostgreSQL, and Python `yfinance`. 

> **STRICT DISCLAIMER:** This is strictly a simulation system using virtual money. It never places real market orders, connects to a broker, or uses real currency. All trades and stop-loss orders are executed virtually.

---

## 💡 What Is This Website Literally Doing? (Plain-English Walkthrough)

If you have never worked with stock trading or paper-trading before, here is **literally what happens from start to finish**:

### 1. The Core Idea: Monopoly / Flight Simulator for Real Stocks
* **You start with ₹100,000 in fake virtual money** in your account.
* **You get real, live stock market data** from Yahoo Finance (for Indian companies like Reliance and Tata, or US companies like Apple and Google).
* **You test trading strategies with ZERO financial risk**: You can buy shares, watch whether they gain or lose value based on real-world stock market movements, sell them to lock in profit, and test automatic safety rules (stop-losses).

---

### 2. What Each Feature Literally Does Step-by-Step

#### A. Stock Search: "What is this stock worth right now?"
1. You type a ticker like `RELIANCE.NS` or `AAPL` into the search box.
2. The browser asks PHP -> PHP runs Python -> Python queries **Yahoo Finance** servers in real time.
3. The page displays the real company name, current price (e.g. ₹1,211.20), currency symbol (`₹` or `$`), and today's percentage change.

#### B. Virtual Buying: "Spend virtual cash to buy shares"
1. You pick a stock and enter how many shares you want (e.g. 10 shares of Reliance at ₹1,211.20 each = ₹12,112.00 total).
2. The website checks: *Do you have at least ₹12,112 in your virtual account?*
   - If **NO**: It rejects the order and tells you that you don't have enough virtual cash.
   - If **YES**: It deducts ₹12,112 from your virtual cash, creates a new holding in your portfolio, and logs a permanent record in your trade history.
3. You can also optionally set a **Stop-Loss** (e.g., *"If this drops by 5% to ₹1,150.64, sell automatically"*).

#### C. The Dashboard & Portfolio: "Am I making or losing money?"
1. When you look at your portfolio, the system calculates two numbers:
   - **Cost Basis:** What you paid when you bought the shares.
   - **Current Market Value:** What those shares are worth right now at current Yahoo Finance prices.
2. It calculates **Unrealised P&L (Floating Profit/Loss)**:
   - If your 10 shares rose from ₹1,211.20 to ₹1,250.00, you are **+₹388.00 in profit** (green).
   - If they dropped to ₹1,180.00, you are **-₹312.00 in loss** (red).
   - This is *unrealised* because you haven't sold the shares yet.

#### D. Virtual Selling: "Cash out and lock in results"
1. You can choose to sell some or all of your shares at the current market price.
2. The system calculates your **Realised P&L** (the actual cash profit or loss you permanently locked in).
3. The sale proceeds are credited straight back into your available virtual cash balance so you can buy other stocks.

#### E. The Automatic Stop-Loss: "A 24/7 safety net robot"
1. Imagine you buy a stock at ₹1,000 and configure a **5% stop-loss** (₹950).
2. You run the background monitoring robot (`python python/monitor_stop_loss.py`).
3. Every 30 seconds, this script checks the real stock market price:
   - If the price is ₹1,020: Everything is fine, does nothing.
   - If bad news hits and the price crashes to ₹945 (at or below your ₹950 threshold):
     - **The robot automatically triggers a virtual sell order.**
     - It sells your shares at ₹945, saves the rest of your money, adds the cash back to your balance, and tags the order in Trade History as `STOP_LOSS_TRIGGERED`.
4. If the monitor is turned off, the website header shows a red badge `🔴 Monitor Inactive`, reminding you that automatic stop-loss checks only occur when the monitor process is running.

---

### 3. Summary of Data Flow (Behind the Scenes)

```
[User Browser]
      │
      ▼
 [PHP Web Pages]  (buy.php, portfolio.php, sell.php, index.php)
      │
      ├──> [PostgreSQL / SQLite Database]  (Stores cash balance, open shares, trades)
      │
      └──> [Python yfinance Script]
                 │
                 ▼
          [Yahoo Finance API] (Retrieves live market prices from NYSE, NASDAQ, NSE)
```

---

## 1. Architecture & Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Frontend** | PHP, HTML5, Vanilla JavaScript, CSS3 | Clean, responsive financial dashboard with summary cards, stock search, modals, and trade calculations. No frontend frameworks. |
| **Backend** | PHP 8.0+ | Core trading business logic, account calculations, stop-loss trigger conditions, and transaction management. |
| **Database** | PostgreSQL (PDO with `pdo_pgsql`) | Atomic transactions for virtual balances, positions, orders, and trade history ledger. |
| **Market Data** | Python (`yfinance`) | Real-time and historical stock data retrieval from Yahoo Finance. |
| **Dev Environment** | Windows with XAMPP, PostgreSQL, VS Code | Standard local Windows development stack. |

---

## 2. Dependencies Explained

### Minimal Dependencies Rule
In strict adherence to the project guidelines, external dependencies have been kept to an absolute minimum:

* **Python Dependencies (`requirements.txt`):**
  * `yfinance`: The single required library to retrieve live stock quotes, company names, currencies, and prices from Yahoo Finance.
* **No unnecessary libraries:**
  * No React, Node.js, FastAPI, Flask, Streamlit, Docker, or Redis.
  * No Pandas, NumPy, TA-Lib, or AI/ML packages installed directly.
* **PHP Extensions:**
  * `pdo_pgsql`: Built-in PHP PDO driver for PostgreSQL database access.
  * (Optional `pdo_sqlite`): Built-in driver enabled for local offline testing if PostgreSQL is not active.

---

## 3. Directory Structure

```
stock-market-automated-system/
├── index.php                 # Main Dashboard with metrics, search, and holdings
├── dashboard.php             # Dashboard alias
├── portfolio.php             # Portfolio management & stop-loss configuration
├── trade_history.php         # Chronological audit ledger of all virtual trades
├── buy.php                   # Virtual stock purchase page with cost calculations
├── sell.php                  # Virtual stock sale page with realised P&L calculation
├── test_system.php           # Comprehensive automated verification suite (11 test scenarios)
├── config/
│   └── database.php          # Environment loader & PDO PostgreSQL connection manager
├── includes/
│   ├── functions.php         # Core trading engine, math, and price-quote wrappers
│   ├── header.php            # Navigation, branding, and monitor status indicator
│   └── footer.php            # Disclaimers, system clock, and instructions modal
├── api/
│   ├── stock_price.php       # JSON endpoint for stock search and price quotes
│   ├── monitor_status.php    # JSON heartbeat endpoint for stop-loss monitor status
│   └── execute_stop_loss.php # API & CLI endpoint for automated stop-loss checks
├── python/
│   ├── stock_data.py         # Yahoo Finance quote fetcher (CLI / Batch / Mock)
│   └── monitor_stop_loss.py  # Background price polling and stop-loss trigger runner
├── database/
│   ├── schema.sql            # PostgreSQL DDL table definitions, indices, and seed data
│   └── init_db.php           # PHP database creation and schema migration script
├── assets/
│   ├── css/
│   │   └── style.css         # Modern, responsive vanilla CSS financial theme
│   └── js/
│       └── app.js            # Vanilla JavaScript client for search, calculations & polling
├── requirements.txt          # Python dependency list (yfinance)
├── .env.example              # Environment variables template
├── .env                      # Local environment configuration
├── .gitignore                # Git exclusions
└── README.md                 # Setup and operational documentation
```

---

## 4. Setup & Installation on Windows

### Step 1: Clone or Place in XAMPP
Place the project files inside your XAMPP web root:
```
C:\xampp\htdocs\h
```

### Step 2: Enable PostgreSQL in XAMPP PHP
Open `C:\xampp\php\php.ini` and ensure the following lines are uncommented:
```ini
extension=pdo_pgsql
extension=pgsql
```
*(This has already been configured on your system).*

### Step 3: Install Python Dependencies
Open PowerShell or Command Prompt and run:
```powershell
python -m pip install -r requirements.txt
```
*Note: If you have Python 3.12 64-bit installed, you can use:*
```powershell
& "C:\Users\Arun Sah\AppData\Local\Programs\Python\Python312\python.exe" -m pip install -r requirements.txt
```

### Step 4: Configure Database Settings
Check your `.env` file and adjust PostgreSQL credentials if needed:
```ini
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=stock_market
DB_USER=postgres
DB_PASSWORD=your_postgres_password
INITIAL_BALANCE=100000.00
CURRENCY_SYMBOL=₹
CURRENCY_CODE=INR
PYTHON_PATH=C:\Users\Arun Sah\AppData\Local\Programs\Python\Python312\python.exe
```

### Step 5: Initialize PostgreSQL Database
Run the automated initialization script from PowerShell:
```powershell
C:\xampp\php\php.exe database/init_db.php
```
This connects to PostgreSQL, creates the `stock_market` database if it does not exist, and executes `database/schema.sql` to establish the tables and seed the initial virtual balance of ₹100,000.

---

## 5. Running the Application on Windows

### A. Starting the Web Server
You can run the application either using XAMPP Apache or the PHP built-in server:

**Option 1: Using XAMPP Apache**
* Open **XAMPP Control Panel** and click **Start** next to Apache.
* Open your browser and navigate to: `http://localhost/h/`

**Option 2: Using PHP Built-In Server**
```powershell
cd C:\xampp\htdocs\h
C:\xampp\php\php.exe -S localhost:8000
```
Then navigate to: `http://localhost:8000/`

---

### B. Starting the Background Stop-Loss Monitor
The stop-loss monitor is a straightforward polling script that continuously checks market prices for open positions with active stop-losses.

**To Start Monitoring:**
Open a separate PowerShell / Command Prompt terminal and run:
```powershell
python python/monitor_stop_loss.py
```
Or with custom polling interval (e.g. 15 seconds):
```powershell
python python/monitor_stop_loss.py --interval 15
```
Or in mock/test mode:
```powershell
python python/monitor_stop_loss.py --mock
```

**To Stop Monitoring:**
Press `Ctrl + C` in the monitoring terminal window. The monitor will exit cleanly and update its heartbeat to Inactive.

> **Status Indicator:** If the monitor is not running, the dashboard header will clearly show: `🔴 Monitor Inactive`. Stop-losses are only checked and executed when this process is running or when manually evaluated via the UI button.

---

## 6. Features & Usage Guide

### 1. Dashboard (`index.php`)
* **Real-Time Summary Cards:** Available virtual cash, portfolio market value, unrealised P&L, realised P&L, and overall net P&L.
* **Live Stock Search:** Enter symbols like `RELIANCE.NS`, `TCS.NS`, `INFY.NS`, `AAPL`, `MSFT`, or `TSLA` to inspect the latest price, currency, and day change.
* **Open Positions Table:** Lists held stocks, average cost, current price, and quick action buttons.
* **Recent Trades Table:** Shows last 10 simulated transactions.
* **Reset Balance Button:** Allows resetting the simulation back to initial ₹100,000 capital for fresh tests.

### 2. Virtual Buying (`buy.php`)
* Enter stock symbol or pick from popular Indian NSE or US stock shortcuts.
* Real-time calculation verifies if your available virtual cash covers `Quantity × Market Price`.
* Configure an optional stop-loss price or percentage at purchase time.
* Atomic database transactions ensure balance deduction and position creation occur consistently.

### 3. Virtual Selling (`sell.php`)
* Liquidate full or partial positions.
* Validates you cannot sell more shares than you hold.
* Calculates realised profit or loss and credits proceeds to your virtual cash balance.

### 4. Automatic Stop-Loss (`portfolio.php`)
* Set a stop-loss price or percentage (e.g. 5% below purchase price).
* When `python/monitor_stop_loss.py` detects market price $\le$ stop-loss price:
  1. The position is automatically sold at the retrieved price.
  2. Proceeds and realised P&L are updated.
  3. The trade is logged with reason `STOP_LOSS_TRIGGERED`.
  4. Duplicate execution is strictly prevented through transaction locks.

### 5. Trade History (`trade_history.php`)
* Full audit ledger of all trades with filters for `All`, `Buys`, `Sells`, and `Stop-Loss Triggers`.

---

## 7. Automated Verification Suite

To run all 11 required test scenarios from the specification, execute:
```powershell
C:\xampp\php\php.exe test_system.php
```

**Tested Scenarios:**
1. ✅ **Successful virtual purchase** (balance deduction & position creation).
2. ✅ **Purchase rejected** due to insufficient virtual cash.
3. ✅ **Successful manual sale** (proceeds credited & remaining shares updated).
4. ✅ **Realised & Unrealised P&L calculations** (weighted average cost accounting).
5. ✅ **Automatic stop-loss triggering** (auto-sold when market price $\le$ stop-loss).
6. ✅ **No duplicate stop-loss execution** (idempotent evaluation under transaction lock).
7. ✅ **Invalid stock symbol handling** (graceful error return without crashing).
8. ✅ **Yahoo Finance connection failure handling** (cached price or error message).
9. ✅ **Missing or stale price handling** (stop-loss refused if price is invalid or stale).
10. ✅ **Database connection failure handling** (clear diagnostic error reporting).
11. ✅ **Application restart data persistence** (portfolio data retained across requests).
